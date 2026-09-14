# Publicar uma demonstração no Render Free

Preparação validada localmente em 14/09/2026. Nenhum recurso externo foi criado durante o desenvolvimento. As telas e os planos podem mudar: confira o [guia gratuito](https://render.com/docs/free), a [referência Blueprint](https://render.com/docs/blueprint-spec) e [extensões PostgreSQL](https://render.com/docs/postgresql-extensions) antes de aplicar.

## Antes de começar

O Git deste projeto contém a pasta `complyflow-ai/`. O Blueprint está em `complyflow-ai/render.yaml`, com `rootDir: complyflow-ai`. O caminho do Dockerfile é relativo a essa raiz de build. Se publicar somente o conteúdo dessa pasta como um novo repositório, retire `rootDir` e use `render.yaml` como caminho do Blueprint; não mantenha um nível inexistente.

Faça push dos arquivos para seu próprio repositório GitHub/GitLab conectado ao Render. Tenha um workspace com vaga para **um** Postgres gratuito. O YAML escolhe explicitamente `free` para web e banco, ambos em Oregon, PostgreSQL17. Não use pre-deploy command, shell remoto, disco persistente ou serviço worker separado: essas capacidades não fazem parte deste plano gratuito.

## Passo a passo no painel

1. Entre no Render Dashboard e escolha o workspace desejado.
2. Clique **New + → Blueprint**. Conecte o provedor Git, autorize somente o repositório necessário e selecione esse repositório.
3. Escolha a branch que contém o projeto. No campo **Blueprint Path**, informe `complyflow-ai/render.yaml`. Se reorganizou o repositório conforme acima, use `render.yaml`.
4. Revise a prévia: `complyflow-ai` deve ser **Web Service / Docker / Free**; `complyflow-db` deve ser **Postgres / Free / 17**; mesma região. Confirme que a estimativa não inclui recursos pagos. Se nomes já existirem no workspace, renomeie ambos no YAML e atualize `fromDatabase.name` antes de aplicar.
5. Clique **Deploy Blueprint** (ou **Apply**, conforme a tela). O Render gera os segredos e a conexão privada automaticamente. Não cole chaves de provedor de IA.
6. Abra o serviço web e acompanhe **Events/Logs**. O build compila Vue e instala dependências; o startup habilita vector, aplica migrations e seed idempotente e aquece caches. O web só fica saudável quando Laravel, banco e Python respondem.
7. Quando o status indicar **Live**, abra a URL `https://...onrender.com` exibida pelo próprio painel. Consulte `/api/health`: os três checks devem ser `ready`. Entre em **Explorar demonstração**, abra NovaGuard, a matriz, revise os quatro itens e registre uma decisão fictícia; recarregue e confira Auditoria.

`APP_URL` é derivada automaticamente de `RENDER_EXTERNAL_URL` antes dos caches. Se adicionar domínio próprio, configure `APP_URL` com a URL HTTPS desse domínio na aba **Environment** e aplique novo deploy. `APP_KEY_MATERIAL` e `PROCESSOR_HMAC_SECRET` são gerados com `generateValue`; `DB_URL` vem de `fromDatabase.connectionString`. Preserve esses valores entre deploys. Não exponha o processador, não configure chaves em Vue/Vite e não habilite APP_DEBUG para investigar falhas públicas.

## Custos e limitações reais

| Recurso | Plano gratuito consultado |
| --- | --- |
| Web | 0,1 CPU e 512 MB; uma instância |
| Inatividade | Hiberna após 15 min sem tráfego; retorno geralmente cerca de 1 min |
| Horas | 750 horas gratuitas por workspace/mês, compartilhadas |
| Filesystem | Efêmero; sem disco persistente, SSH ou one-off jobs |
| Postgres | 0,1 CPU/256 MB de RAM e **1 GB de armazenamento** |
| Validade do banco | 30 dias, depois 14 dias de carência para upgrade; sem acesso normal após expiração |
| Operação do banco | Um banco gratuito/workspace, sem backups gerenciados nem pooling gratuito |

Limites de banda/build e políticas de gratuidade também se aplicam. Sem cartão, quotas esgotadas podem suspender o serviço; não considere o plano um SLA. Um job de fundo não garante que o web permaneça acordado. O filesystem não guarda uploads: binários, sessões e fila ficam no Postgres. A imagem local foi testada com 512 MiB/0,1 CPU, mas desempenho local não garante latência ou capacidade no Render. OCR desativado e concorrência mínima; use PDFs fictícios pequenos e pouco tráfego.

## Banco expirado: recriação da demo

A carência permite upgrade para plano pago, não estende a demonstração gratuita operacional. Para continuar gratuitamente, será preciso excluir o banco expirado e criar outro; **isso perde todo histórico e todas as sessões**. Não faça essa operação sobre dados que precise preservar. O template de portfólio é reconstruível, as alterações feitas pelos visitantes não são um backup.

1. No painel do banco, confirme o nome/ID exato e o estado expirado. Se houver dados importantes, planeje exportação antes de vencer ou upgrade; este guia não oferece recuperação após exclusão.
2. Para uma demo descartável, exclua apenas `complyflow-db` pela ação de exclusão do painel. Não exclua outros serviços/workspaces.
3. Na configuração do Blueprint, use um novo nome, por exemplo `complyflow-db-demo-02`, **nas duas referências**: `databases[].name` e `services[].envVars[].fromDatabase.name`. Faça commit/push e sincronize o Blueprint. Confira novamente `plan: free` e mesma região antes de aplicar.
4. Aguarde a nova base e o deploy web. A referência atualizada injeta `DB_URL`; o startup habilita vector e restaura papéis/template. Não exige shell nem tarefa avulsa.
5. Verifique health, abra uma nova demo e repita a jornada. Cookies antigos não recuperam sessões do banco anterior.

## Verificação local e diagnóstico

```bash
bash scripts/production-smoke.sh
```

Esse script utiliza banco separado descartável, testa a imagem sem bind de fontes e a reinicia; depois roda Playwright contra Nginx/PHP-FPM reais. O schema JSON oficial pode ser validado com Python `jsonschema` e PyYAML ou pela integração SchemaStore do editor:

```bash
curl -fsSL https://render.com/schema/render.yaml.json -o /tmp/render-schema.json
python3 -c 'import json,jsonschema,yaml; jsonschema.validate(yaml.safe_load(open("render.yaml")), json.load(open("/tmp/render-schema.json")))'
```

Validação de schema não cria recursos nem confirma disponibilidade/cotas da conta. A validação oficial por API/CLI pode pedir credenciais; ela não foi usada aqui.

Health503: confira disponibilidade/expiração do banco e logs de processos. Erro de inicialização: confira `DB_URL`, permissões/extensão vector e se o banco pertence ao Blueprint correto. O entrypoint falha fechado e não imprime conexão ou material da chave. Para reproduzir com detalhes, use a base local fictícia; não publique logs com credenciais. 419 de CSRF: use sempre a mesma URL HTTPS, verifique APP_URL, cookies e domínio. Após cold start, aguarde inicialização antes de repetir uma operação.
