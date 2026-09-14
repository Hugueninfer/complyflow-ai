# Contribuir

Use uma branch própria e dados estritamente fictícios. Execute o quickstart do [README](README.md). Antes de mudar contratos, leia [arquitetura](docs/architecture.md), [API](docs/api.md) e [segurança](docs/security.md).

Para comportamento novo ou correção, inclua uma regressão que falhe pelo motivo certo e depois implemente. Teste tenant/RBAC no servidor, não apenas botões. Nunca introduza decisão autônoma de fornecedor, credencial pública, consulta externa ficticiamente validada ou overwrite de auditoria. Migrations devem permitir implantação reproduzível; alterações do template exigem migração explícita, pois o seed preserva o histórico existente.

```bash
docker compose stop queue
bash scripts/verify.sh
bash scripts/production-smoke.sh
```

Os scripts usam bancos descartáveis e podem apagar tabelas/volumes locais conforme documentado. Mudanças visuais devem incluir evidência de desktop/mobile e inspeção de foco, teclado, estados vazios/erro/permissão. Nunca capture PII real. Mudanças no parser precisam manter correspondência entre PDF, páginas e trechos; consulte [demo](docs/demo.md) para geração dos fixtures.

Abra PR com problema, comportamento resultante, testes executados e limitações. Atualize os docs/contratos pertinentes. Não versione `.env`, logs, resultados de testes ou dependências instaladas. Preserve LICENSE e licenças de terceiros. Relate vulnerabilidades em privado ao responsável pelo repositório, com reprodução local fictícia.
