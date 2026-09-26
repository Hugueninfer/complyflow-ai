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

Um nome pertence a uma única linhagem por organização, independentemente da versão. A comparação mantém a igualdade exata e sensível a maiúsculas do PostgreSQL (`A` e `a` são diferentes), após o tratamento habitual de espaços externos na entrada HTTP. Versões da mesma linhagem podem compartilhar nome; nomes ainda presentes em versões excluídas continuam reservados. Criação e renomeação rejeitam nomes de outra linhagem com 422 em `errors.name`, inclusive em concorrência. Todas as mutações de checklist serializam primeiro a organização com `FOR NO KEY UPDATE`, depois raiz → versão. Essa ordem acompanha análises/decisões e permite os locks `KEY SHARE` das chaves estrangeiras.

Publicação, criação de versão e início de análise também rejeitam colisões legadas com 422 em `errors.name`, antes de congelar o checklist ou criar trabalho/cobrar cota. Não há migração que una linhagens ou reescreva histórico silenciosamente. Um rascunho conflitante pode ser corrigido pelo endpoint de edição usando um nome livre. Colisões que envolvam nomes publicados/excluídos exigem um plano de reparo administrativo explícito, com backup e mapeamento revisado de IDs/linhagens; até esse reparo, as operações afetadas falham de forma segura. Esta consulta somente de leitura identifica nomes com mais de uma raiz após a migration de normalização de linhagens:

```sql
SELECT organization_id, name, array_agg(DISTINCT COALESCE(parent_id, id)) AS roots
FROM requirement_sets
GROUP BY organization_id, name
HAVING count(DISTINCT COALESCE(parent_id, id)) > 1;
```

## Cadastro e primeira análise na SPA

**Criar conta** no login usa `POST /api/v1/register` com `name`, `email`, `organization_name`, `password` (mínimo 12 caracteres) e `password_confirmation` igual. Escolha suas próprias credenciais; não há conta/senha compartilhada. O endpoint inicia a sessão de owner e o frontend consulta `/me` para obter organização, papel e permissões. Campos inválidos recebem feedback associado ao campo; as senhas são limpas após a resposta ou ao alternar o modo de acesso.

Cadastro e login normalizam `email` com `trim` + minúsculas antes da validação/consulta. A busca considera contas legadas em maiúsculas. Duplicatas, inclusive duas requisições que passam a validação antes do primeiro commit, retornam 422 com `errors.email`; a transação evita organizações órfãs. O limitador público mantém a mesma identidade normalizada.

No dossiê, a permissão existente `analysis.run` habilita **Iniciar nova análise**. O formulário lista versões publicadas e PDFs do fornecedor nos estados `uploaded` (recebidos) ou `ready` (processados). `processing`, `failed` e estados desconhecidos não entram na seleção. Exige 1–10 PDFs e até 15 MiB; oferece links para requisitos/upload quando faltam pré-requisitos. Esses filtros da interface não substituem a autorização, o tenant, as cotas e a validação de entrada do servidor.

A SPA envia `POST /api/v1/suppliers/{id}/analyses` com `requirement_set_id`, `document_ids` e `Idempotency-Key` gerada por Web Crypto. Reutiliza a chave em uma tentativa repetida com a mesma seleção após falha de rede; uma seleção alterada ou nova execução usa outra chave. Respostas 202 (criação) e 200 (repetição idêntica) abrem `/analises/{uuid}`. Erros 409/422/429 são apresentados de forma segura; 401 encerra a sessão na interface. No Render, exceder o orçamento global de requisitos retorna `429` com `{"code":"ai_daily_quota_exceeded","message":"Daily AI analysis quota exceeded."}` sem criar run nem job. Envios simultâneos e navegação por resposta antiga são bloqueados localmente. Uma análise `pending`/`processing` no dossiê oferece acompanhamento; após `completed`/`failed`, é possível iniciar outra execução. A idempotência do formulário fica na memória da tela; o servidor conserva a proteção durável por chave. Antes do envio, a interface informa os termos de dados do serviço Gemini gratuito e restringe o uso pretendido a PDFs fictícios.

`e2e/tests/owner-first-analysis.spec.ts` percorre essa jornada pela interface com HTTP, CSRF, fila e FastAPI reais, até a matriz e a evidência. `tests/production/smoke.py` acrescenta verificações de protocolo e persistência na imagem final.
