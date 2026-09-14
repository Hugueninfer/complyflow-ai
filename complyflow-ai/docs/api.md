# API pública e contrato interno

Mesma origem da SPA. Prefixo de negócio `/api/v1`, JSON, UUIDs públicos. Inicialize sessão com `GET /sanctum/csrf-cookie`; mutações enviam o cookie de sessão, `X-XSRF-TOKEN` decodificado do cookie XSRF e `Accept: application/json`. O navegador nunca recebe segredo HMAC nem acessa Python.

| Operação | Endpoint |
| --- | --- |
| Saúde sem autenticação | `GET /api/health` |
| Cadastro/login/logout | `POST /api/v1/register`, `/login`, `/logout` |
| Demo isolada | `POST /api/v1/demo-sessions` |
| Sessão/papel/permissões | `GET /api/v1/me` |
| Indicadores/comparação | `GET /api/v1/dashboard`, `/comparisons` |
| Fornecedores | `GET/POST /api/v1/suppliers`, `GET/PUT/PATCH/DELETE /api/v1/suppliers/{id}` |
| Documentos | `GET/POST /api/v1/suppliers/{id}/documents`, `GET /api/v1/documents/{id}` |
| Checklists | `GET/POST /api/v1/requirement-sets`, `GET/PUT/PATCH/DELETE /api/v1/requirement-sets/{id}` |
| Publicar/criar versão | `POST /api/v1/requirement-sets/{id}/publish`, `/versions` |
| Iniciar análise | `POST /api/v1/suppliers/{id}/analyses` |
| Estado/achados | `GET /api/v1/analyses/{id}`, `/api/v1/analyses/{id}/findings` |
| Nova execução após falha | `POST /api/v1/analyses/{id}/retry` |
| Revisar achado | `POST /api/v1/findings/{id}/reviews` |
| Decisão humana | `POST /api/v1/suppliers/{id}/decisions` |
| Auditoria | `GET /api/v1/audit-logs` |

O health retorna `{"status":"ready","checks":{"laravel":"ready","database":"ready","processor":"ready"}}` com 200. Dependência indisponível retorna 503 e somente rótulos; não inclui exception, endereço ou credencial. O probe verifica consulta PostgreSQL e chamada curta ao `/health` Python; não atesta throughput ou consumo da fila.

Listas de negócio retornam `data` e paginação quando aplicável; `/me` e autenticação têm projeções próprias. Erros seguem os status Laravel: 401 sessão ausente, 403 permissão, 404 recurso inacessível, 409 conflito, 413 limite, 419 CSRF, 422 validação, 429 rate limit. A API ainda não padroniza todos os erros em um envelope único com `request_id`; o cliente usa status/códigos permitidos e não renderiza mensagens remotas arbitrárias.

Análises, retries, revisões e decisões exigem `Idempotency-Key`. Não reutilize a chave com outro corpo. Revisão também envia `expected_review_id` para controle de concorrência. Contratos completos e exemplos: [análises](processor-orchestration.md), [revisão/decisão/auditoria](human-review-audit.md), [dashboard/comparações](dashboard-comparison.md), [demo](demo.md).

O contrato interno `POST /v1/analyze` é [OpenAPI 3.1](../services/processor/openapi/processor.yaml), verificado contra a aplicação. [Assinatura, janela e replay](../services/processor/openapi/README.md). O schema é apenas uma fronteira: ambas as aplicações também conferem correspondência literal de trecho/página e IDs do snapshot.

## Pesos e versões de checklists

`requirements.*.weight` aceita números de `0.001` a `999.999` na criação/edição; quando omitido, o peso padrão é `1`. O mínimo corresponde à precisão `decimal(6,3)` do banco e impede arredondamento de pesos positivos para zero. Publicação e início de análise revalidam pesos persistidos, inclusive dados legados. Peso inválido retorna 422 com a chave `errors.requirements.N.weight`, sem publicar, criar análise, consumir cota ou enfileirar trabalho. O contrato interno continua exigindo peso estritamente positivo em PHP/Pydantic.

Uma nova versão parte da última versão publicada ativa da linhagem e exige ausência de rascunho ativo. O número é `max(version) + 1` considerando também exclusões lógicas; v1 publicada → v2 rascunho → excluir v2 → criar a partir de v1 produz v3. Renomear uma versão não muda sua linhagem. Fonte obsoleta, fonte não publicada ou rascunho ativo retorna 409; recurso de outro tenant ou excluído retorna 404; falta de permissão retorna 403. Escritas da linhagem adquirem locks na ordem raiz → versão. Chamadas concorrentes são serializadas: uma cria o rascunho (201), a seguinte observa esse rascunho e retorna 409. O índice único permanece como proteção adicional contra colisões.

## Operações ainda sem formulário na SPA

Cadastro owner usa `POST /api/v1/register` com `name`, `email`, `organization_name`, `password` (mínimo 12 caracteres) e `password_confirmation` igual. Escolha suas próprias credenciais; não há conta/senha compartilhada. O endpoint inicia uma sessão no cliente HTTP; depois é possível usar o formulário de login do navegador com essas credenciais.

A primeira análise usa `POST /api/v1/suppliers/{id}/analyses` com `requirement_set_id` e `document_ids`, além de `Idempotency-Key`. Após receber o UUID, abra `/analises/{uuid}` na SPA autenticada para acompanhar. O formulário inicial de seleção não está implementado; o botão de retry de análise falha já está. `tests/production/smoke.py` demonstra, com HTTP/CSRF reais e dados fictícios, cadastro owner, checklist, PDF e primeira análise até a persistência.
