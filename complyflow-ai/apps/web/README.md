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

Para Vite fora do Compose, defina `API_PROXY_TARGET=http://localhost:8000`. No Compose, `/api` e `/sanctum` são encaminhados para `http://api:8000`, mantendo o navegador na mesma origem. Na produção, Laravel serve a SPA e a API sob o mesmo domínio.

## Contratos

`api.get<T>(path)` e `api.post<T>(path, body?)` retornam `Promise<{ data: T }>`. `data` é o JSON completo do servidor; uma resposta Laravel `{ data: ... }` está, portanto, em `response.data.data`. Exemplo:

```ts
const response = await api.get<Envelope<Session>>('/me')
const session = response.data.data
```

O cliente envia `credentials: include`, inicializa `/sanctum/csrf-cookie` antes de mutações e envia o cookie decodificado em `X-XSRF-TOKEN`. Um 419 renova CSRF e repete uma única vez. Erros de negócio/500 não são repetidos automaticamente. 401 limpa a identidade em memória e encaminha a uma nova entrada. Mensagens de erro públicas são localizadas e não mostram stack traces do servidor.

`useAuthStore()` oferece `session`, `isAuthenticated`, `can(permission)`, `bootstrap()`, `refresh()`, `login(email, password)`, `startDemo()` e `logout()`. O bootstrap chama `GET /api/v1/me`. Login e demo preservam seus contratos anteriores e em seguida consultam `/me`, que fornece `{ user, organization, role, permissions, demo }`. Somente dados autenticados pelo servidor alimentam organização, papel e permissões. Nenhum token, senha ou papel é persistido em Web Storage. A senha do formulário é descartada após a tentativa.

`createAppRouter(pinia, history?)` instala os guards `meta.requiresAuth` e `meta.permission`; esses guards servem à experiência, enquanto o backend impõe autorização e isolamento. A rota `/login` é pública. As rotas de negócio usam temporariamente `WorkspaceView`: esta tarefa entrega a fundação, e as tarefas 14–16 substituem essas áreas pelas telas completas.

`StatusBadge` recebe `{ status: FindingStatus }`; `FindingStatus = 'met' | 'partial' | 'missing' | 'inconclusive'`, exportado de `src/types/domain.ts`. Cada estado tem texto, ícone SVG com nome acessível e cores semânticas. Os tokens ficam em `src/styles/tokens.css` e o layout compartilhado em `src/styles/app.css`.

## Acessibilidade e referências

Sidebar fixa de 260px em desktop (1280px+); drawer em tablet/mobile, com foco inicial, ciclo Tab/Shift+Tab, Escape, restauração de foco, fundo `inert` e bloqueio do scroll. O shell inclui skip link, títulos e navegação com nomes acessíveis. Formulários têm labels, autocomplete, erros anunciados, carregamento e bloqueio de envios concorrentes. Foco visível e `prefers-reduced-motion` são tratados globalmente.

O login, shell e tokens seguem `Sovereign Compliance Interface` e as referências locais do Stitch: navy estrutural, ação teal, índigo reservado à IA, cartões de evidência e marca SVG. As métricas ilustrativas não são exibidas como dados reais; a prévia é identificada como fictícia. Selos de certificação, SSO, recuperação de senha e integrações ausentes foram omitidos. Dashboard e demais métricas reais pertencem às próximas tarefas.
