# Orquestração Laravel–FastAPI

`POST /api/v1/suppliers/{supplier_uuid}/analyses` exige sessão autenticada,
permissão `analysis.run` e header `Idempotency-Key` (1–255 caracteres ASCII:
letras, números, `_`, `.`, `:`, `-`). O corpo contém `requirement_set_id`
(UUID de uma versão publicada) e `document_ids` (lista de 1–10 UUIDs distintos).
O checklist deve conter entre 1 e 100 requisitos, e a seleção de PDFs deve
somar no máximo 15 MiB. Esses limites são verificados antes de criar o run,
consumir quota ou agendar o job; respostas 422 usam mensagens estáveis.
Todos os registros são resolvidos novamente no tenant autenticado; documentos
devem pertencer ao fornecedor. O servidor responde `202` para uma nova análise,
`200` para repetição idêntica e `409` se a chave já foi usada com outra entrada.
`GET /api/v1/analyses/{analysis_uuid}` exige `analysis.view` e expõe estado,
tentativas, progresso, timestamps e erros sanitizados, sem IDs internos.

`POST /api/v1/analyses/{analysis_uuid}/retry` exige `analysis.run`, análise original `failed` no tenant e `Idempotency-Key` ASCII (1–128 caracteres, letras/números/`._:-`). O corpo é vazio: fornecedor, checklist publicado e documentos são resolvidos pelo servidor a partir do original. Cria outra análise com novo UUID; o estado terminal original nunca muda. A chave é prefixada internamente com o UUID de origem para isolar repetições. Mesma origem/chave retorna 200 e o mesmo novo run; criação retorna 202. Reaplica seleção de 1–10 PDFs, checklist, propriedade, limite de bytes, cotas demo e throttle. Snapshot legado sem documentos retorna 422. O fingerprint dos mesmos documentos/checklist permanece o mesmo; UUID e chave distinguem a nova execução.

As rotas Vue `/analises/:id` e `/analises/:id/matriz` consomem esses contratos. A consulta começa imediatamente, mantém uma única chamada por vez, espera 2500 ms após cada resposta e para em `completed`/`failed` ou HTTP 401/403/404/422. Falhas transitórias/429 usam backoff exponencial limitado a 30 s; abas ocultas suspendem novas consultas. Navegação/desmontagem aborta o transporte e invalida respostas antigas. A linha do tempo utiliza apenas criação, início e término informados pelo servidor.

O fingerprint SHA-256 inclui tenant, UUID do fornecedor, UUID e versão do
checklist e hashes dos documentos ordenados. `analysis_runs.document_ids`
preserva a seleção original. Uma constraint única por tenant/chave, locks de
tenant e `firstOrCreate` impedem duplicação concorrente. Quota demo é consumida
apenas na criação. O job entra na fila após o commit externo; rollback não
agenda trabalho. Existe uma janela de falha de processo entre o commit e o
callback da fila; recuperação por outbox não faz parte desta etapa.

Configure `PROCESSOR_HMAC_SECRET` no `.env` raiz, com um segredo aleatório
compartilhado entre API, worker e processador. O Compose fornece a mesma
variável aos três serviços; não há segredo padrão. Fora do Compose, configure
também `PROCESSOR_URL`. Nunca exponha esse segredo ao navegador.

O orçamento absoluto `PROCESSOR_ANALYSIS_TIMEOUT_SECONDS` é **45 s** por
requisição, medido por relógio monotônico desde a entrada na rota Python.
Inclui recebimento/autenticação, validação, extração de todos os PDFs, chunks,
vetores, recuperação, todas as chamadas ao provedor e serialização da resposta.
O processo PDF recebe `min(30 s, restante)`; cada chamada ao provedor recebe
`min(20 s, restante)`, incluindo conexão, headers e corpo lento. Os laços de
preparação/recuperação verificam o mesmo token cooperativo. Um novo requisito
ou PDF não reinicia o prazo. O fake permanece determinístico, local e sem rede.

O prazo global pode ser reduzido, mas deve ser finito, positivo e no máximo
45 s. `PROCESSOR_HTTP_TIMEOUT_SECONDS` configura o transporte Laravel: padrão
60 s, no máximo 60 s e pelo menos **15 s acima** do orçamento Python. Configure
o mesmo orçamento na API, worker Laravel e processador; Compose o compartilha
e a imagem de produção define os dois padrões. Configuração incoerente falha
antes do envio; configuração Python inválida retorna `analysis_not_configured`.

| Limite | Padrão |
| --- | --- |
| Requisição completa no Python | 45 s |
| HTTP Laravel (conexão até 5 s, sem redirects) | 60 s |
| `ProcessAnalysis` | 75 s |
| Lock `WithoutOverlapping` | 85 s |
| `retry_after` database | 90 s local; 120 s na produção |

Ao esgotar o orçamento, Python responde 503 com `analysis_budget_exceeded`,
antes do timeout do cliente. A rota acompanha `http.disconnect` e cancelamento
da tarefa ASGI, sinalizando o token usado pelo worker. Esperas de pipe/HTTP
verificam cancelamento em até 50 ms de execução do escalonador; o subprocesso
PDF e seus descendentes são terminados/recolhidos, e o cliente HTTP é fechado.
São limites cooperativos de aplicação, sujeitos à disponibilidade de CPU e ao
tempo de liberação de recursos, não garantias de tempo real sob saturação.

Para o runtime de 512 MiB/0,1 CPU há **uma análise ativa por processo**, sem fila
interna. Um registro atômico rejeita o mesmo UUID ou hash de chave com 503
`analysis_in_progress`; outra análise durante ocupação recebe 503
`analysis_capacity_exceeded`. A entrada é liberada em `finally` pelo worker
somente quando ele sai, inclusive após erro/cancelamento e serialização. O
término do HTTP não libera trabalho ainda ativo para um retry. Não há cache
de respostas, histórico de chaves nem lease com TTL que expire durante trabalho.
O registro guarda no máximo uma entrada e some no reinício; não fornece
idempotência durável. Use um único processo/worker Python: múltiplas réplicas
exigem coordenação compartilhada tanto de execuções quanto dos nonces HMAC.

Há quatro tentativas com backoff de 10, 30 e 90 s; cada chamada recebe nonce
novo. Esgotamento, ocupação, conexão, 408, 429 e 5xx são retentáveis;
`provider_not_configured`, `analysis_not_configured`, outros 4xx e resposta
inválida são terminais. Um timeout do worker Laravel falha de forma terminal.
Os limites de 100 requisitos/10 PDFs não garantem conclusão em 45 s: um
conjunto ou provedor lento pode esgotar todas as tentativas, exigindo menor
escopo ou mudança operacional. Não são persistidos resultados parciais.
Mensagens remotas e exceções com conteúdo documental não são propagadas.

Cada tentativa registra o UUID da mensagem, o ID da reserva na fila database
e seu número de tentativa. O callback `failed()` só finaliza a reserva
proprietária: duplicatas que esgotem tentativas em releases por sobreposição
não finalizam uma execução alheia, inclusive quando o proprietário está em
`pending` durante backoff e o lock está livre. Se um processo for morto durante
a última reserva, a recuperação no preflight exige uma `DatabaseJob` com o
mesmo UUID e ID da linha reservada, estado `processing`, tentativa imediatamente
anterior e aquisição do lock de sobreposição. A fila database mantém o ID ao
retomar uma reserva expirada, mas cria outro ID em releases/backoff. Lock livre
isoladamente não comprova interrupção nem autoriza finalizar outro job.
A resposta HTTP passa por nova checagem de propriedade/estado sob lock de
linha antes da transação de persistência, impedindo `failed` → `completed`.

Payloads serializados antigos continuam executáveis: a propriedade é obtida
da reserva real durante `handle()`, sem depender de novos campos serializados.
Registros legados interrompidos sem identidade de reserva permanecem sem
conclusão automática (fail-closed); exigem reconciliação operacional. A
recuperação de crash não infere identidade para outros drivers de fila.

## Persistência de resultados e matriz

`ProcessorClient::analyze(AnalysisRun): ProcessorResult` verifica tipos estritos,
objetos fechados, listas JSON, enums, revisão humana obrigatória, IDs exatos,
páginas, chunks de 384 dimensões e evidência com offsets em caracteres Unicode.
O cliente envia apenas os campos do contrato canônico; nomes de arquivo e
metadados de armazenamento não saem do Laravel.

`ResultPersister::handle(AnalysisRun, ProcessorResult): void` está ligado a
`PersistProcessorResult`. Ele repete o contrato Laravel e verifica novamente
os requisitos da versão publicada, tenant, fornecedor, seleção e fingerprint.
Páginas, chunks, achados, citações e estado `completed`/100% são gravados na
mesma transação. `ProcessorResult` expõe `analysisId`, `findings` (DTOs
`FindingResult` com `CitationResult`) e `processedDocuments` (arrays já
validados, com nomes de campos iguais ao contrato Python).

O persister bloqueia o run e compara tentativa, UUID da mensagem e ID/número
da reserva antes de qualquer escrita; também bloqueia fornecedor, checklist
e documentos, nesta ordem. Respostas obsoletas e entregas duplicadas não
alteram estados terminais. Resultados inválidos finalizam apenas a tentativa
atual com `invalid_processor_result`, sem artefatos parciais. Erros de banco
causam rollback e são substituídos por `analysis_failed` sanitizado para retry.

Extrações idênticas reutilizam páginas e chunks; citações repetidas no mesmo
achado são deduplicadas. Texto ou conjunto de páginas divergente de uma
extração persistida é rejeitado, preservando citações históricas. A troca de
algoritmo de extração que altere texto exige um desenho futuro de versões de
extração. Embeddings são finitos, de 384 dimensões e compatíveis com float32
do pgvector. Textos com NUL são rejeitados antes da escrita PostgreSQL.
O indicador OCR é validado, mas não armazenado no esquema atual.

`GET /api/v1/analyses/{analysis_uuid}/findings` exige autenticação e
`analysis.view` no tenant da análise. Retorna UUIDs públicos, requisito,
status sugerido, justificativa, confiança, resumo de busca e citações com
documento/página/trecho/offsets Unicode. A ordem é posição/código/UUID do
requisito; citações usam documento/página/offsets/UUID. A resposta inclui
`requires_human_review: true` e nunca cria revisão ou decisão automática.
Blobs, texto completo de páginas, vetores e nomes de armazenamento não são
consultados para esta resposta. Matriz sem achados retorna `data: []`.

Análises já terminais não são executadas novamente; análises que falharam
antes da integração do persister exigem nova chave para outro run. Exceções
tardias não rebaixam uma análise concluída em transação.

## Verificação

Na raiz do repositório:

```bash
docker compose run --rm api php artisan test tests/Feature/Analysis tests/Unit/Processor
bash tests/contracts/processor-hmac.sh
docker compose run --rm api php artisan test
```

O teste entre linguagens usa a fixture canônica imutável
`services/processor/openapi/hmac-test-vector.json`. PHP assina seus bytes e
emite os bytes/headers do transporte HTTP Laravel; Python os submete ao
autenticador HMAC e ao schema real via FastAPI TestClient, com relógio fixado,
verificando também replay e a relação entre os prazos efetivos de ambos os
processos, job e lock. Somente o pipeline de extração é substituído, pois
o PDF da fixture contém apenas um cabeçalho e não é um PDF completo.
