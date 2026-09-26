# Arquitetura

O Laravel é a fonte de verdade. O Vue recebe projeções explícitas de recursos autenticados; o processador Python não acessa diretamente o banco nem persiste decisões. No Compose, processos têm containers próprios. No Render gratuito, o empacotamento é único para caber no plano, preservando contratos entre aplicações. O comando Python remove DB_URL e chaves Laravel do seu ambiente, mas processos sob o mesmo UID/container não formam uma fronteira rígida de isolamento.

## Fronteiras e persistência

`apps/api`: Laravel 13/PHP 8.4, Sanctum, validações, policies, tenants, fila database, serviços de domínio e PostgreSQL. `apps/web`: Vue 3, TypeScript, Vite 8, Pinia, Vue Router, Tailwind e fontes locais. `services/processor`: FastAPI/Python 3.12, pypdf, recuperação híbrida e provedores. `demo-assets`/`output/pdf`: PDFs fictícios reproduzíveis e manifest de extração. `infra`: ambientes local e produção.

Entidades de negócio possuem `organization_id` e UUID público. IDs enviados pelo navegador são resolvidos outra vez dentro do tenant. PDFs limitados são persistidos em `document_blobs.contents` (`bytea`); filesystem é só temporário. Páginas e chunks são persistidos com a análise, com vetores pgvector de 384 dimensões. A busca atual combina hashing vetorial determinístico e texto no Python; não executa consulta ANN no PostgreSQL.

## Uma análise

1. Uma pessoa autorizada escolhe versão publicada de requisitos e 1–10 PDFs do fornecedor. A soma máxima é 15 MiB e o checklist tem até 100 requisitos.
2. Laravel valida escopo, cotas e chave idempotente. No Render, uma trava transacional global serializa o orçamento compartilhado de 20 requisitos por dia UTC; replay idempotente não consome novamente. Depois persiste `pending` e agenda após commit.
3. Um worker adquire lock e identidade da reserva, persiste `processing` e chama o Python com HMAC dos bytes exatos, timestamp e nonce.
4. Python valida autenticação/schema, extrai páginas em subprocesso limitado, separa chunks, recupera até cinco contextos por requisito e valida a saída do provedor. O Render seleciona Gemini; desenvolvimento e CI usam o fake determinístico sem rede. OCR opcional existe como adaptador, mas não está conectado ao pipeline público.
5. Laravel repete validações de IDs, estado, páginas, citações e offsets. Persiste artefatos e `completed` na mesma transação; falhas expõem códigos sanitizados.
6. A pessoa revisa achados; outra operação explícita registra a decisão, somente para análise/checklist atuais e após todas as revisões obrigatórias.

[Contrato e política de retries](processor-orchestration.md) · [Semântica das métricas e comparação](dashboard-comparison.md).

## Imagem única

Stages separados compilam Vue, instalam Composer sem dev com autoload otimizado e montam wheels Python fixados. Runtime contém PHP-FPM, bibliotecas Python necessárias, Nginx e Supervisor; não contém `.env`, Node, Composer, testes ou fontes Vue. O UID é 10001. Somente `storage`, caches e `/tmp` são graváveis pelo app. Nginx expõe `PORT` (10000 por padrão), serve deep links da SPA e encaminha `/api` e `/sanctum` ao FPM; Python escuta apenas loopback.

Há um processo de fila, um worker Uvicorn e no máximo um filho FPM. PHP limita cada processo a 96 MiB, fila recicla a 80 MiB e OPcache usa 32 MiB. A extração já limita seu subprocesso a 256 MiB de espaço de endereçamento/20 s de CPU/30 s de relógio; isso não é um orçamento agregado garantido. O smoke impõe 512 MiB/0,1 CPU no container; PDFs hostis e carga concorrente exigem capacidade maior. Supervisor recebe TERM como PID 1 e propaga parada; o prazo final do host pode interromper jobs ainda ativos.

O entrypoint deriva uma chave Laravel de 32 bytes a partir de material aleatório estável. Depois de resolver `APP_URL` pelo ambiente/Render, mantém advisory lock de sessão durante extensão vector, migrations, seed idempotente, purge de demos vencidas e caches. Falha de inicialização impede servir tráfego. Caches são criados em runtime, nunca incorporam segredos durante build. Reinícios executam o mesmo caminho; a trava protege a primeira migração durante sobreposição transitória de deploys.

Entradas expiradas da store de cache/limitação PostgreSQL são coletadas em lotes de até 32 no startup e em cada requisição Laravel, restritas ao prefixo da aplicação. Um advisory lock transacional não bloqueante e locks de linha `SKIP LOCKED` evitam competir com outro coletor ou renovar/apagar uma entrada ativa. Timeouts locais limitam o custo; falha de manutenção é sanitizada e fail-open, sem desativar auth/CSRF/throttle. Não há cron ou chave permanente de coordenação. Detalhes de escopo e capacidade estão em [Segurança](security.md#retenção-do-cache-de-aplicação).

## Compromissos conhecidos

Replay HMAC é local ao processo e se perde em reinício. Há uma janela entre commit da análise e enqueue que pede outbox em produção. Interrupções repetidas podem exigir reconciliação operacional. Auditoria não possui âncora externa. Matrizes retornam todo checklist e ainda não foram testadas em grande escala. A limpeza automática de **tenants demo** acontece no startup: expiração bloqueia acesso exatamente após 24h, mas a exclusão física pode ocorrer mais tarde; operação contínua precisa scheduler externo ou processo periódico. Isso é separado da coleta de cache por requisição. Não mantenha o serviço acordado artificialmente para contornar limites gratuitos.
