# Dashboard e comparação

As duas consultas usam Sanctum, organização resolvida no servidor e as permissões `supplier.view`, `requirement.view` e `analysis.view`. A comparação também exige `document.view`, pois inclui trechos de evidência. Os papéis existentes `owner`, `analyst` e `reviewer` possuem essas permissões. As próprias queries autorizam o ator; elas não dependem apenas do controller. IDs de tenant enviados pelo cliente não alteram o escopo.

As respostas são somente leitura. Não geram score, ranking, recomendação de aprovação/reprovação ou decisão automática. A confiança pertence ao achado da IA e não representa nota do fornecedor. A decisão humana continua no fluxo descrito em [Revisão humana e auditoria](human-review-audit.md).

## Dashboard

`GET /api/v1/dashboard`

```json
{
  "data": {
    "suppliers_analyzed": 2,
    "requirements_met": 2,
    "pending_requirements": 2,
    "analyses_awaiting_review": 1
  }
}
```

Os quatro campos são inteiros, inclusive no estado vazio, que retorna quatro zeros.

Primeiro é escolhida **uma análise por fornecedor**, a de maior ID interno de inserção, independentemente do checklist e do status. Isso coincide com a definição de análise mais recente do fluxo de decisão. Datas de conclusão e UUIDs não decidem empates. Uma análise posterior `pending`, `processing` ou `failed` torna as anteriores históricas e as remove das métricas atuais. Este dashboard não é uma contagem histórica de todas as execuções.

A análise selecionada só participa quando está `completed`, o fornecedor está ativo e o checklist é a maior versão publicada da mesma linhagem. Checklist e raiz da linhagem precisam estar ativos. Uma versão draft posterior não invalida a publicada. Uma nova publicação invalida as análises da versão anterior até existir análise pertinente; não se reaproveitam achados de outra versão. Linhagens diferentes não competem entre si para definir a versão publicada atual.

| Campo | Definição |
| --- | --- |
| `suppliers_analyzed` | Fornecedores distintos com análise selecionada e elegível. |
| `requirements_met` | Pares análise–requisito cujo status efetivo é `met`. Inclui requisitos obrigatórios e opcionais do checklist selecionado. |
| `pending_requirements` | Pares análise–requisito com `partial`, `missing`, `inconclusive` ou sem achado. Não conta jobs pendentes. |
| `analyses_awaiting_review` | Análises elegíveis sem decisão final e com pelo menos um requisito obrigatório sem achado ou sem revisão humana. Cada análise conta uma vez. |

Status efetivo é o da revisão humana mais recente do achado, quando houver; caso contrário é o status sugerido pela IA. Revisão mais recente significa maior ID de inserção, preservando correções append-only e resolvendo timestamps iguais. A contagem `requirements_met` pode portanto incluir sugestões ainda não revisadas; a interface deve apresentá-la como status efetivo assistivo, sem sugerir certificação humana. `requirements_met + pending_requirements` cobre todos os requisitos das análises elegíveis, uma vez cada. O mesmo requisito aplicado a dois fornecedores representa dois pares distintos. Requisitos opcionais sem revisão não bloqueiam o fluxo obrigatório de revisão/decisão. Uma revisão `missing` já cumpriu a etapa de revisão, mesmo que sua pendência documental permaneça.

Checklist vazio pode ter fornecedor analisado, mas produz zero requisitos e zero análises aguardando revisão. Decisão final não altera os status da IA ou da revisão. Nenhum conteúdo de documentos, achados ou justificativas é carregado para agregar o dashboard.

## Comparação

`GET /api/v1/comparisons?left={supplier_uuid}&right={supplier_uuid}&requirement_set={version_uuid}`

Os dois fornecedores devem ser diferentes e ativos; os três UUIDs são obrigatórios e resolvidos dentro do tenant. `requirement_set` identifica uma versão exata de checklist publicado. Uma versão publicada histórica pode ser consultada e recebe `is_current: false`; draft não pode ser comparado. Checklist/raiz removidos retornam `404`.

Cada lado seleciona a análise `completed` de maior ID de inserção para aquele fornecedor e a versão **exata** solicitada. Uma execução posterior incompleta não substitui o resultado concluído nessa consulta histórica. O campo `is_latest_for_supplier` indica se a análise exibida também é a mais recente do fornecedor considerando todos os estados e checklists. Ele pode ser `false` mesmo quando `requirement_set.is_current` é `true`. Esses dois indicadores devem ficar visíveis ao consumidor para não apresentar um resultado histórico como elegível para decisão. Nenhum deles autoriza uma decisão: o endpoint de decisão revalida suas próprias regras.

Formato de resposta (UUIDs abaixo abreviados somente para leitura):

```json
{
  "data": {
    "requirement_set": {"id": "uuid", "name": "Checklist", "version": 1, "is_current": true},
    "left": {
      "supplier": {"id": "uuid", "name": "Fornecedor A"},
      "analysis": {"id": "uuid", "completed_at": "2026-09-13T12:00:00+00:00", "is_latest_for_supplier": true}
    },
    "right": {"supplier": {"id": "uuid", "name": "Fornecedor B"}, "analysis": null},
    "rows": [
      {
        "requirement": {"id": "uuid", "code": "CERT", "title": "Certificado", "category": "Compliance", "position": 1, "is_required": true},
        "left": {
          "finding_id": "uuid",
          "ai": {"status": "met", "justification": "Evidência localizada.", "confidence": 0.8, "search_summary": null},
          "human_review": {"id": "uuid", "status": "partial", "justification": "Escopo limitado.", "note": null, "reviewed_at": "2026-09-13T12:30:00+00:00"},
          "requires_human_review": false,
          "evidence": [{"id": "uuid", "document_id": "uuid", "page_number": 1, "quote": "Trecho resumido", "quote_truncated": false, "start_offset": 0, "end_offset": 14}],
          "evidence_total": 1
        },
        "right": null
      }
    ]
  }
}
```

`rows` contém todos os requisitos da versão, alinhados pelo UUID do requisito, em ordem crescente de `position`, `code`, UUID. A ordem e os lados solicitados não dependem do resultado dos fornecedores ou da ordem em que o processador inseriu achados. Checklist vazio retorna `rows: []`. Lado sem análise concluída pertinente tem `analysis: null`; célula sem achado é `null`, sem inventar `missing`. `human_review` é `null` na ausência de revisão. Quando existe, é a correção de maior ID do achado selecionado; revisões de outras execuções não são reaproveitadas. `requires_human_review` indica ausência de revisão daquele achado e não elegibilidade para decisão.

`evidence` tem no máximo três citações, ordenadas por UUID do documento, página, offsets e UUID da citação. `evidence_total` conta todas as citações visíveis e válidas para o escopo, antes desse limite. Cada `quote` contém no máximo 240 caracteres Unicode, sem reticências inseridas. `quote_truncated` indica corte; os offsets continuam referindo-se à citação original completa. Para inspeção integral da evidência, usar a matriz `/api/v1/analyses/{analysis}/findings`. Documento/página/citação devem pertencer ao tenant, a página deve pertencer ao documento, e o documento deve pertencer ao fornecedor e ao snapshot `document_ids` da análise. Documentos removidos são omitidos.

Os timestamps são ISO 8601 UTC; `completed_at` pode ser `null` em dados legados. Respostas expõem UUIDs e campos explícitos, sem IDs internos, nomes de armazenamento, conteúdo binário, texto integral de páginas, chunks ou embeddings. As consultas de evidência fazem corte de texto e limite por achado no banco, usando funções de janela testadas em PostgreSQL. A quantidade de consultas não cresce por requisito, revisão ou citação: requisitos, achados, revisões vigentes e resumos de evidência são obtidos em lotes.

## Erros e verificação

- `401`: sessão ausente.
- `403`: alguma permissão de leitura necessária ausente.
- `404`: UUID válido inexistente, recurso de outro tenant ou recurso removido.
- `422`: parâmetro ausente/malformado ou ambos os fornecedores resolvem para o mesmo registro, inclusive UUIDs com caixa diferente.
- `409`: checklist existe no tenant, mas não está publicado.

Executar `docker compose run --rm api php artisan test tests/Feature/Dashboard tests/Feature/Comparison`. Os testes rodam sobre PostgreSQL do Compose e cobrem agregação, versões, estados vazios, isolamento/IDOR, RBAC, correções humanas, ordem, limites de evidência, ausência de ranking e consultas constantes.
