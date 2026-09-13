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

O job usa lock `WithoutOverlapping`, timeout de 75 s, lock com expiração de
85 s e `retry_after` da fila de 90 s. O HTTP possui conexão de 5 s e timeout de
60 s, sem redirects. Há quatro tentativas com backoff de 10, 30 e 90 s;
cada chamada recebe nonce novo. Erros de conexão, 408, 429 e 5xx são
retentáveis; `provider_not_configured`, outros 4xx e resposta inválida são
terminais. Um timeout do worker falha de forma terminal. Mensagens remotas e
exceções com conteúdo de documentos não são propagadas ao erro público.

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
verificando também replay. Somente o pipeline de extração é substituído, pois
o PDF da fixture contém apenas um cabeçalho e não é um PDF completo.
