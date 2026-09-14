# ComplyFlow AI — aplicação Vue

Vue 3, Composition API, TypeScript, Pinia e Vue Router. Vite mantém o pipeline de desenvolvimento/build e Tailwind CSS 4 é processado pelo plugin oficial. Fontes Manrope, Inter e JetBrains Mono são empacotadas localmente, assim como os ícones SVG; a página não depende de imagens ou CDNs remotos.

## Execução e verificação

Na raiz `complyflow-ai/`, com os containers iniciados e as migrations aplicadas:

```sh
docker compose exec api php artisan db:seed --force
docker compose run --rm web npm ci
docker compose up -d web api
docker compose run --rm web npm run test
docker compose run --rm web npm run typecheck
docker compose run --rm web npm run lint
docker compose run --rm web npm run build
docker compose exec web npm run test:session-http
```

O seed padrão fornece os papéis/permissões necessários para entrar na demo. O smoke HTTP cria uma demo isolada, testa CSRF, restauração por cookie e logout; a organização criada expira em 24 horas. Ele usa `http://localhost:5173`, configurável por `WEB_BASE_URL`. `artisan serve --no-reload` preserva as variáveis de ambiente do Compose no processo HTTP filho; sem esse argumento, o Laravel pode descartar `DB_*` e carregar o SQLite do `.env` local.

Para Vite fora do Compose, defina `API_PROXY_TARGET=http://localhost:8000`. No Compose, `/api` e `/sanctum` são encaminhados para `http://api:8000`, mantendo o navegador na mesma origem. Na produção, Nginx serve a SPA e encaminha a API ao Laravel sob o mesmo domínio.

## Contratos

`api.get<T>(path)`, `api.post<T>(path, body?)`, `api.put<T>(path, body?)` e `api.delete<T>(path)` retornam `Promise<{ data: T; status: number }>`. `data` é o JSON completo do servidor; uma resposta Laravel `{ data: ... }` está, portanto, em `response.data.data`. Exemplo:

```ts
const response = await api.get<Envelope<Session>>('/me')
const session = response.data.data
```

O cliente envia `credentials: include`, inicializa `/sanctum/csrf-cookie` antes de mutações e envia o cookie decodificado em `X-XSRF-TOKEN`. Um 419 renova CSRF e repete uma única vez. Erros de negócio/500 não são repetidos automaticamente. 401 limpa a identidade em memória e encaminha a uma nova entrada. Mensagens de erro públicas são localizadas e não mostram stack traces do servidor.

`useAuthStore()` oferece `session`, `isAuthenticated`, `can(permission)`, `bootstrap()`, `refresh()`, `login(email, password)`, `startDemo()` e `logout()`. O bootstrap chama `GET /api/v1/me`. Login e demo preservam seus contratos anteriores e em seguida consultam `/me`, que fornece `{ user, organization, role, permissions, demo }`. Somente dados autenticados pelo servidor alimentam organização, papel e permissões. Nenhum token, senha ou papel é persistido em Web Storage. A senha do formulário é descartada após a tentativa.

`createAppRouter(pinia, history?)` instala os guards `meta.requiresAuth` e `meta.permission`; esses guards servem à experiência, enquanto o backend impõe autorização e isolamento. A rota `/login` é pública. Fornecedores, dossiê, requisitos, upload, dashboard, análises, matriz, revisões, comparações e auditoria têm telas integradas à API.

### Fornecedores, requisitos e documentos

- `/fornecedores`: `GET/POST /suppliers`, filtros locais por nome/identificação fiscal e risco. `/fornecedores/:id`: `GET/PUT/DELETE /suppliers/:id`. Campos: `name`, `tax_id` opcional/nulo e `risk_level` (`low`, `medium`, `high`). Exclusão exige confirmação na tela e `supplier.update`, conforme a policy existente.
- `/requisitos`: `GET/POST /requirement-sets`, `PUT /requirement-sets/:id`, `POST /requirement-sets/:id/publish` e `POST /requirement-sets/:id/versions`. O editor envia nome e a lista completa de requisitos (código único, título, categoria, peso de 0 a 999,999, posição, critério de avaliação e obrigatoriedade). Versões publicadas aparecem somente para consulta; nova versão gera um rascunho. Publicação tem confirmação explícita e permissão própria.
- `/fornecedores/:id/documentos`: `POST /suppliers/:id/documents` com `FormData` e campo `file`. O cliente deixa o navegador definir o boundary multipart. Seleção e drag-and-drop aceitam um PDF por envio, com extensão `.pdf`, MIME `application/pdf` ou vazio quando o navegador não conhece o tipo, tamanho maior que zero e até 5 MiB. MIME explicitamente incompatível é rejeitado antecipadamente. O servidor revalida assinatura, MIME, tamanho, contagem, quota e hash. `201` indica criação; `200` indica deduplicação, sem criar nova linha. O transporte `fetch` não fornece progresso de bytes de upload: a UI usa progresso indeterminado durante envio/validação, sem porcentagem simulada.
- Dossiê e upload consultam `GET /suppliers/:id/documents?page=N`. Essa extensão metadata-only retorna `{ data: DocumentMetadata[], meta: { current_page, last_page, total } }`, 25 registros por página, ordenados por ID interno decrescente como critério estável (o ID interno nunca é exposto). A resolução por UUID é limitada ao tenant; `document.view` é exigida mesmo para listas vazias. Campos públicos: `id`, `storage_name`, `mime_type`, `size_bytes`, `sha256`, `status`. Sem bytes, URLs de download, nomes originais, IDs internos ou prévia executável de arquivos.

`ApiError.fields` localiza os campos indicados por respostas Laravel 422, com mensagens públicas genéricas em português. Mensagens arbitrárias do servidor não são refletidas. `401` encerra a sessão local; `403`, `404`, `409`, `413`, `422` e `429` têm feedback localizado. Erros de rede/negócio exigem nova tentativa explícita; somente a renovação CSRF 419 é repetida automaticamente uma vez.

Quota total da demo esgotada retorna `413` com `code: "demo_storage_quota_exceeded"` e mensagem segura estável. O cliente reconhece somente esse código permitido nesse status, expõe `ApiError.code` e orienta iniciar outra demonstração, sem culpar o tamanho de um PDF pequeno. O `413` por limite do arquivo continua indicando 5 MiB. A exceção de quota preserva a interface HTTP existente para serviços e testes concorrentes.

`useResourceCollection` ordena leituras por geração e reconcilia cada GET com gravações concluídas depois de seu início. Uma resposta antiga não remove um fornecedor/rascunho recém-salvo; uma leitura posterior pode atualizar esses registros com dados novos do servidor. Respostas de tentativas substituídas ou telas desmontadas são descartadas.

As ações dependem de `supplier.create/update`, `requirement.create/update/publish` e `document.upload`; consultas dependem de `supplier.view`, `requirement.view` e `document.view`. Esconder controles e bloquear rotas é somente UX. O Laravel continua responsável por RBAC e isolamento. A demo atual usa o papel `reviewer`; ele consulta cadastros/documentos/requisitos, mas não cria fornecedores, envia arquivos nem publica requisitos. A população do template da demo pertence ao seed da jornada completa; para testar mutações, use uma sessão owner/analyst de QA com as permissões adequadas.

`StatusBadge` recebe `{ status: FindingStatus }`; `FindingStatus = 'met' | 'partial' | 'missing' | 'inconclusive'`, exportado de `src/types/domain.ts`. Cada estado tem texto, ícone SVG com nome acessível e cores semânticas. Os tokens ficam em `src/styles/tokens.css` e o layout compartilhado em `src/styles/app.css`.

## Acessibilidade e referências

Sidebar fixa de 260px em desktop (1280px+); drawer em tablet/mobile, com foco inicial, ciclo Tab/Shift+Tab, Escape, restauração de foco, fundo `inert` e bloqueio do scroll. O shell inclui skip link, títulos e navegação com nomes acessíveis. Formulários têm labels, autocomplete, erros anunciados, carregamento e bloqueio de envios concorrentes. Foco visível e `prefers-reduced-motion` são tratados globalmente.

O login, shell e tokens seguem `Sovereign Compliance Interface` e as referências locais do Stitch: navy estrutural, ação teal, índigo reservado à IA, cartões de evidência e marca SVG. As métricas do dashboard vêm da API; a demo é identificada como fictícia. Selos de certificação, SSO, recuperação de senha e integrações ausentes foram omitidos.
