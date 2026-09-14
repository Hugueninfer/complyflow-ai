# Revisão humana e auditoria

Todas as rotas abaixo usam a sessão Sanctum e a organização resolvida no servidor. IDs externos são UUIDs. `owner` e `reviewer` têm as permissões `finding.review`, `supplier.decide` e `audit.view`; `analyst` não as possui.

## Revisar um achado

`POST /api/v1/findings/{finding}/reviews`

```json
{"status":"partial","justification":"Escopo limitado após inspeção humana.","note":"Observação opcional.","expected_review_id":null}
```

Enviar `Idempotency-Key` obrigatório, de 1 a 128 caracteres alfanuméricos ou `._:-`. `status` aceita `met`, `partial`, `missing`, `inconclusive`. Justificativa é obrigatória; justificativa e observação têm limite de 10.000 caracteres. A resposta `data` contém `id`, `finding_id`, `status`, `justification`, `note`, `reviewed_at`.

Cada correção cria uma revisão e mantém o achado original da IA. A análise precisa estar concluída. Após sua decisão final, novas revisões nessa análise são bloqueadas. Para corrigir uma conclusão final, iniciar outra análise e revisá-la.

`expected_review_id` é o UUID público da última revisão vista pelo usuário. O campo aceita `null` ou ausência somente quando não há revisão anterior. Uma versão obsoleta retorna 409 sob o mesmo lock de organização usado pela gravação. Uma repetição com chave e corpo idênticos continua retornando o registro existente antes de comparar a versão, inclusive se o cliente perdeu a resposta anterior. Correções precisam de uma nova chave e do ID atual.

`GET /api/v1/analyses/{id}/findings` inclui `latest_review` (null ou os mesmos campos públicos da resposta de revisão) e `review_locked` derivado de estado não concluído/decisão final existente. O status e a justificativa no nível do achado continuam sendo os originais da IA. O frontend exige confirmação explícita de inspeção humana, renderiza notas como texto e, após 409, bloqueia novo envio e preserva o rascunho para leitura e cópia, mesmo se a atualização revelar uma decisão final. Sem decisão final, fechar e reabrir a evidência permite inspecionar a revisão atualizada antes de outro parecer.

## Registrar decisão final

`POST /api/v1/suppliers/{supplier}/decisions`

```json
{"analysis_id":"UUID-da-analise","decision":"conditional","reason":"Justificativa humana da decisão."}
```

Enviar `Idempotency-Key` com as mesmas regras. `decision` aceita `approved`, `rejected`, `conditional`; `reason` é obrigatório, com limite de 10.000 caracteres. A resposta `data` contém `id`, `supplier_id`, `analysis_id`, `requirement_set_id`, `decision`, `reason`, `decided_at`.

A análise deve pertencer ao fornecedor e tenant, estar concluída e ser a mais recente desse fornecedor, mesmo quando a análise posterior ainda está pendente ou falhou. O checklist deve ser a versão publicada mais recente da mesma linhagem. Todo requisito obrigatório dessa versão precisa ter achado nessa análise e pelo menos uma revisão humana. O status revisado não produz decisão automaticamente: a escolha final é sempre explícita pelo usuário autorizado.

Existe uma decisão imutável por análise. Revisões e decisões usam chaves idempotentes separadas, únicas por organização e operação. Repetir a mesma chave e conteúdo pelo mesmo ator retorna o registro original (`200`); criação retorna `201`. Reutilizar a chave com conteúdo/ator/alvo diferente retorna `409`. Nova chave para análise já decidida também retorna `409`. IDs inválidos ou fora do tenant retornam `404`, permissões insuficientes `403`, validação `422` e conflito de estado `409`.

### Contexto de decisão na matriz

Para atores com `supplier.decide`, o GET de findings acrescenta `meta.decision_context` com `analysis_id`, `supplier_id`, `requirement_set_id`, `status`, `required_pending`, `is_latest_for_supplier`, `is_current_checklist` e `decision`. IDs são UUIDs; fornecedor ou checklist removidos produzem ID nulo e não habilitam a ação. `required_pending` inclui requisitos obrigatórios sem achado ou sem revisão. `decision` é nulo ou a projeção do registro persistido (`id`, `decision`, `reason`, `decided_at`), sem IDs internos ou dados de idempotência. A projeção só orienta a interface; o POST revalida todas as regras sob os locks existentes e permanece a fonte de verdade.

Na matriz `/analises/{id}/matriz`, o formulário final fica separado da IA e exige escolha, justificativa e confirmação explícitas. Só é oferecido após todos os requisitos obrigatórios revisados, análise atual concluída e checklist atual. Reenvio do mesmo corpo após resposta incerta conserva a chave. Conflitos 409 e permissão 403 bloqueiam novo envio, preservando a justificativa como texto copiável, inclusive se o GET de atualização falhar ou retornar sem contexto de decisão por perda de permissão. O rascunho local permanece somente leitura, mesmo após atualização das permissões; não habilita gravação sem contexto/autorização. Sair da análise ou recarregar a página descarta esse rascunho não registrado. Após sucesso, o foco vai ao resultado humano imutável; recarregar a rota mostra a mesma decisão persistida. O formulário não produz aprovação automática.

## Consultar auditoria

`GET /api/v1/audit-logs?page=1&per_page=25`

Retorna `data` e `meta` (`current_page`, `last_page`, `per_page`, `total`). O limite máximo é 100 eventos por página. A ordem é inversa de inserção, determinada pelo ID interno, que não é exposto. Cada evento contém UUID do evento, UUIDs preservados de organização e ator, ação, tipo/UUID do alvo, metadados permitidos, hashes e timestamp UTC.

`meta.integrity` contém `scope: "page"` e `status: "verified" | "broken" | "unverifiable" | "empty"`. Em cada leitura, o servidor reutiliza `AuditHash::make` para recalcular os hashes dos eventos da página, confere as ligações entre eles e a referência ao predecessor imediatamente fora da página (uma consulta adicional limitada). Na última página, a ausência real de predecessor exige hash anterior nulo. Um predecessor existente, mas sem snapshot/hash, torna a fronteira não verificável, mesmo quando ambos os hashes comparados são nulos; uma quebra comprovada do hash de um evento ainda prevalece sobre essa incerteza. Não varre a cadeia inteira nem atesta páginas que não foram consultadas. Registros sem snapshot de organização/hash são identificados como legados não verificáveis; ausência de eventos não é apresentada como cadeia íntegra. A interface `/auditoria` expõe esses limites, hashes em detalhes técnicos acessíveis e alerta de quebra, com ícones, cores e nomes acessíveis distintos para cada estado. Metadados legados nulos são suportados.

`AuditLogger::record(AuditEvent $event)` serializa a cabeça da cadeia sob lock da organização, inclusive para o primeiro evento. Revisão/decisão e respectivo evento são gravados na mesma transação. Metadados aceitam somente UUIDs e enums específicos da ação; justificativas, observações, e-mails, nomes, PDFs e trechos não entram na auditoria.

Mutações de checklist descobrem a raiz sem bloquear descendentes, adquirem a raiz e só depois a versão solicitada. A decisão segue a mesma ordem para a linhagem. Decisão e início de análise usam `FOR NO KEY UPDATE` na organização enquanto esperam checklists: mantêm a exclusão entre escritores do tenant e permitem o `KEY SHARE` exigido pela chave estrangeira de uma nova versão. O logger adquire seu lock de auditoria depois que a decisão já possui a linhagem. Isso evita tanto a inversão raiz/versão quanto o ciclo indireto pela chave estrangeira da organização.

`AuditHash::verifyChain()` recebe a cadeia completa de um tenant em ordem crescente de inserção. O hash SHA-256 inclui UUID do evento, UUIDs preservados da organização e ator, ação, tipo/UUID do alvo, timestamp UTC com precisão de segundos, payload JSON com chaves ordenadas recursivamente e hash anterior. Arrays preservam a ordem. O encadeamento detecta alterações, remoções intermediárias e reordenação. Sem âncora externa, não comprova ausência de truncamento no fim da cadeia ou reescrita integral por administrador do banco; não oferece certificação de tempo ou não repúdio.

Modelos e triggers PostgreSQL bloqueiam update/delete de revisões, decisões e logs. A única exceção de retenção é `DELETE` de demo expirada pelo comando `demo:purge-expired`: exige flag local à transação com o ID exato do tenant e expiração confirmada no banco. `UPDATE` nunca é liberado. O comando remove primeiro o histórico, depois os dados do tenant. A flag não autoriza exclusão de tenants ativos e não é exposta por HTTP.

O esquema aceita snapshots/chaves nulos em registros anteriores à implantação deste recurso; somente novos eventos criados pelo logger possuem o contrato da cadeia descrito aqui. Não há tentativa de reescrever histórico legado para torná-lo artificialmente verificável.
