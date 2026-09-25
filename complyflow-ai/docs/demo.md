# Demonstração fictícia

O template reservado `demo-template` chama-se Atlas Industrial Demo e não possui vínculos de usuários após o commit do seed. Os três atores fictícios de referência não têm senha conhecida nem acesso ao template. Não existem credenciais públicas de administrador. O botão de demonstração cria um revisor e uma organização exclusivos com expiração exata de 24 horas.

O cookie persistente e a sessão PostgreSQL têm janela de inatividade de pelo menos 1.440 minutos: retornar após três horas sem atividade recupera a mesma demo no mesmo navegador. A atividade renova essa janela técnica, mas nunca altera o `expires_at` original. Em `expires_at <= agora`, o servidor bloqueia acesso com 401 e invalida a sessão usada, mesmo se o cookie ainda não venceu. Fechar o navegador normalmente preserva o cookie; modo privado, limpeza de cookies, logout ou perda do banco/chave impedem retomar a demo, que não possui senha de recuperação. A purga física é posterior e não determina o momento do bloqueio.

NovaGuard Facilities aguarda revisão dos quatro requisitos. Boreal Suprimentos Demo possui quatro revisões e uma decisão humana fictícia condicionada. Vértice Logística Demo ainda não tem análise. O checklist publicado Homologação 2026 inclui `met`, `partial`, `missing` e `inconclusive`; são resultados ilustrativos pré-carregados, e nenhuma decisão é tomada pela IA. Os mesmos três documentos genéricos fictícios são vinculados aos dois fornecedores analisados, sem atribuição a uma empresa real.

Cada clone começa com 3/10 fornecedores, 2/3 análises e 16.416/15.728.640 bytes consumidos. A cota restante não concede permissões: o visitante é reviewer e pode inspecionar, revisar e decidir. O comando existente `demo:purge-expired` remove apenas demos vencidas.

O seed usa transação e lock PostgreSQL para evitar duplicações concorrentes. Reexecutá-lo preserva identidades e histórico existentes; ele não redefine demos já abertas. Alterações posteriores na versão do template exigem uma migração explícita. A criação dos eventos do template usa autorização temporária dentro da transação, removida antes do commit. Cada clone reatribui revisões e decisões ao ator da sessão e registra novos eventos via AuditLogger. Os textos identificam o histórico pré-carregado como fictício. UUIDs, cabeças e hashes de auditoria não são copiados. O histórico clonado é uma inicialização ilustrativa, não uma afirmação de que o visitante realizou essas ações anteriormente.

## Executar

Em uma base local descartável (o primeiro comando apaga as tabelas dessa base):

```sh
docker compose run --rm api php artisan migrate:fresh --seed
docker compose run --rm api php artisan db:seed --class=DemoTemplateSeeder
docker compose up -d api web
docker compose --profile e2e run --build --rm e2e npx playwright test
```

Playwright usa Chromium real, sem respostas HTTP simuladas, retries ou esperas fixas. O container E2E está apenas na rede interna Docker. A imagem e dependências precisam ser baixadas na preparação; a jornada consulta os resultados locais e não exige provedor externo, processo de IA ou chave paga. Um proxy HTTP de transporte em `127.0.0.1:4173` encaminha as requisições ao Vite real, permitido apenas pelo hostname explícito `web`. O loopback fornece Web Crypto para as chaves idempotentes, sem alterar a configuração de segurança do navegador ou da aplicação publicada. O proxy não fornece dados, fixtures nem respostas de negócio.

Execute testes Laravel antes de reconstruir a base para o navegador: a configuração Compose atual usa o banco local nos testes e as migrations dos testes podem apagá-lo.

## PDFs reproduzíveis

Os três PDFs finais estão em `output/pdf/`. Os nomes estáveis de `demo-assets/` são symlinks relativos versionados, resolvidos no bind mount e quando todo o repositório é copiado para uma imagem. `manifest.json` guarda SHA-256, bytes, páginas extraídas com normalização de whitespace e trechos reais. O seed confere bytes/hash antes de persistir os blobs `bytea`, páginas, chunks e citações.

```sh
python -m pip install -r demo-assets/requirements.txt
python demo-assets/generate.py
mkdir -p tmp/pdfs
pdftoppm -png output/pdf/certidao-ficticia.pdf tmp/pdfs/certidao
pdftoppm -png output/pdf/politica-privacidade-ficticia.pdf tmp/pdfs/politica
pdftoppm -png output/pdf/balanco-ficticio.pdf tmp/pdfs/balanco
```

O gerador ReportLab fixa os metadados variáveis e não usa rede, marcas, CNPJ, nomes ou assinaturas de pessoas reais. Cada página contém `DOCUMENTO FICTÍCIO - SOMENTE DEMONSTRAÇÃO`. Os PDFs não comprovam regularidade real e não possuem formulários, JavaScript ou assinaturas digitais. Re-renderize e inspecione todas as páginas após qualquer alteração do gerador.
