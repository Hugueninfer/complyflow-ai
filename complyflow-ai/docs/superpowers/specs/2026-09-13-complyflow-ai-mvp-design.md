# ComplyFlow AI — Especificação do MVP

**Data:** 13 de setembro de 2026  
**Status:** desenho aprovado, aguardando revisão final antes do plano de implementação

## 1. Visão do produto

O ComplyFlow AI é uma plataforma multi-tenant para análise assistida de fornecedores e conformidade documental. Uma organização cadastra fornecedores e requisitos, envia PDFs e solicita uma análise. O sistema produz uma matriz por requisito com status, justificativa, confiança e evidências localizadas por documento, página e trecho.

A IA é apenas assistiva. Ela nunca aprova nem reprova um fornecedor. Um usuário autorizado deve revisar os achados e registrar a decisão final, que fica preservada na trilha de auditoria.

O MVP deve funcionar sem serviços pagos, ser demonstrável sem chave de IA, executar localmente com Docker Compose e estar preparado para hospedagem gratuita no Render.

## 2. Metas de sucesso

- Entregar a jornada completa: entrar, cadastrar ou explorar fornecedor, analisar documentos, revisar achados e registrar uma decisão humana.
- Impedir acesso cruzado entre organizações em todas as operações de servidor.
- Tornar cada conclusão rastreável a uma evidência válida em uma página existente.
- Oferecer uma demonstração isolada por visitante, com dados fictícios e duração exata de 24 horas.
- Executar a suíte automatizada de Laravel, Vue, FastAPI e navegador por comandos documentados.
- Permitir reconstruir o banco gratuito do Render com migrations e seeds.
- Apresentar qualidade visual e documentação suficientes para uso como case de portfólio.

## 3. Escopo funcional

### Incluído

- Cadastro, login, logout e sessão com Laravel Sanctum.
- Organizações isoladas e papéis `owner`, `analyst` e `reviewer`.
- Cadastro de fornecedores e conjuntos de requisitos versionados.
- Upload seguro de PDFs fictícios.
- Análises assíncronas com estados `pending`, `processing`, `completed` e `failed`.
- Matriz com estados `met`, `partial`, `missing` e `inconclusive`.
- Justificativa, confiança, documento, página, trecho e indicação de revisão humana.
- Confirmação ou correção dos achados por revisor.
- Decisão final exclusivamente humana.
- Auditoria append-only.
- Dashboard básico e comparação entre dois fornecedores.
- Dados completos de demonstração e tenant exclusivo por visitante por 24 horas.
- Provedor de IA fake determinístico e adaptador real opcional compatível com API OpenAI.

### Fora do MVP

- Cobrança, assinaturas, aplicativo mobile, marketplace, ERP, assinatura digital, agentes autônomos, Kubernetes, processamento distribuído e microsserviços adicionais.
- Consultas reais à Receita Federal, Caixa, certificadoras, ERPs ou outras bases externas.
- SSO, SMS, e-mail transacional e alegações de certificações externas.
- Aprovação ou reprovação automática de fornecedor.

## 4. Arquitetura

O repositório será um monorepo com estas unidades:

```text
complyflow-ai/
├── apps/
│   ├── api/                  # Laravel 13 / PHP 8.4
│   └── web/                  # Vue 3 / TypeScript / Vite / Tailwind CSS
├── services/
│   └── processor/            # FastAPI / Python 3.12
├── infra/
│   ├── docker/
│   └── render/
├── docs/
├── demo-assets/              # PDFs estritamente fictícios
├── compose.yaml
└── render.yaml
```

### 4.1 Laravel

Laravel é a fonte de verdade e responde por autenticação, tenants, RBAC, fornecedores, requisitos, documentos, análises, resultados aceitos, revisões, decisões, auditoria, demos e API consumida pelo Vue. Todas as mutações de negócio passam por policies e serviços de aplicação.

Laravel 13 é compatível com PHP 8.4. A restrição oficial do framework é PHP 8.3–8.5. O host atual não possui PHP ou Composer, portanto os comandos serão executados em containers.

### 4.2 Vue

A SPA usará Vue 3, TypeScript, Vue Router e Pinia. Vite fará o desenvolvimento e o build; o bundle de produção será servido pelo Laravel. O frontend não terá autoridade de segurança: tenant e permissões sempre serão impostos no backend.

O container de build usará Node 22, pois o Node 18 disponível no host não atende ao requisito atual do Vite 8 (Node 20.19+ ou 22.12+).

### 4.3 FastAPI

O serviço Python é stateless e executa extração de texto, OCR opcional, separação em páginas, chunking, embeddings, recuperação híbrida simples, reranking determinístico, extração estruturada, confiança e validações de evidência. Ele não persiste diretamente resultados de negócio e não atua apenas como proxy de LLM.

### 4.4 Comunicação interna

Laravel chama o FastAPI por HTTP interno. Cada solicitação inclui:

- token HMAC assinado;
- identificador da análise e chave de idempotência;
- timestamp e expiração curta;
- hash do corpo;
- IDs públicos necessários, sem confiar neles como autorização.

O FastAPI valida assinatura, expiração, replay e schema. O navegador nunca acessa o FastAPI diretamente.

### 4.5 Execução no Render gratuito

Um único web service Docker executará Nginx, PHP-FPM, o worker da fila Laravel e FastAPI sob supervisão. FastAPI escutará apenas em `127.0.0.1`; Nginx exporá a aplicação Laravel na porta fornecida por `PORT`.

Essa composição reduz isolamento operacional, mas conserva fronteiras claras no código e cabe no limite gratuito. Em uma evolução paga, Laravel, worker e FastAPI podem ser separados sem mudar seus contratos.

O PostgreSQL gratuito do Render terá `pgvector` habilitado por `CREATE EXTENSION vector`. O plano gratuito atual oferece 1 GB, expira após 30 dias, não possui backups e pode passar por manutenção ou reinício. O web service hiberna após 15 minutos sem tráfego e possui filesystem efêmero. Essas limitações serão mostradas no README e não são adequadas para produção.

## 5. Modelo de dados

Todas as entidades de negócio usam uma chave interna eficiente e um UUID público. Rotas e respostas expõem apenas UUIDs. Entidades tenant-aware armazenam `organization_id`, e relações recebidas do cliente são resolvidas novamente dentro do tenant autenticado.

| Tabela | Responsabilidade principal |
|---|---|
| `organizations` | Tenant e configurações básicas. |
| `users` | Identidade global do usuário. |
| `organization_user` | Vínculo do usuário com organização e papel. |
| `roles`, `permissions`, `role_permission` | RBAC explícito por operação. |
| `suppliers` | Fornecedores pertencentes ao tenant. |
| `requirement_sets` | Checklist versionado e seu estado. |
| `requirements` | Critério, categoria, peso, ordem e texto de avaliação. |
| `documents` | Metadados seguros, SHA-256, MIME, tamanho e nome gerado. |
| `document_blobs` | Conteúdo binário temporário e limitado do PDF. |
| `document_pages` | Texto extraído e número real da página. |
| `document_chunks` | Trecho, offsets, página e embedding `vector`. |
| `analysis_runs` | Estado, tentativas, idempotência, progresso e erro sanitizado. |
| `analysis_findings` | Resultado sugerido por requisito, justificativa e confiança. |
| `finding_citations` | Documento, página, trecho e offsets da evidência. |
| `finding_reviews` | Registro imutável da revisão e eventual correção humana. |
| `supplier_decisions` | Decisão final humana, autor, justificativa e timestamp. |
| `audit_logs` | Evento append-only com ator, alvo, metadados e hash encadeado. |
| `demo_sessions` | Tenant temporário, token, cotas e expiração. |

### 5.1 Regras de integridade

- Uma citação deve referenciar documento da mesma análise e página existente.
- Um achado concluído exige ao menos uma citação, exceto `missing`, que deve registrar a busca realizada e a ausência de evidência.
- Confiança é um decimal entre 0 e 1 e nunca substitui revisão humana.
- Um `analysis_run` é único por tenant, fornecedor, versão do checklist, conjunto de hashes dos documentos e chave idempotente.
- Apenas `owner` e `reviewer` podem revisar achados e registrar decisão final.
- Revisões, decisões e auditoria não são atualizadas; correções geram novos registros.
- Exclusões de cadastros de negócio são lógicas quando houver histórico associado.

## 6. Fluxos

### 6.1 Autenticação e demo

O usuário pode criar uma conta ou entrar em uma demonstração sem credenciais. A entrada na demo cria uma organização exclusiva, copia um conjunto pequeno de dados fictícios e define `expires_at` com 24 horas exatas. O token da demo só acessa esse tenant. Escritas respeitam cotas por sessão. Um comando agendável remove blobs e dados expirados em lotes idempotentes.

### 6.2 Upload

Laravel valida autorização, tamanho configurável, MIME detectado no servidor, assinatura `%PDF`, quantidade máxima e duplicidade por hash. O nome original é apenas metadado sanitizado; o nome de armazenamento é gerado pelo servidor. No Render gratuito, o binário limitado é armazenado em `document_blobs` para sobreviver a reinícios. A configuração inicial limitará cada PDF a 5 MB e cada demo a 15 MB totais.

### 6.3 Análise

1. Laravel cria ou reutiliza uma análise idempotente em `pending`.
2. O worker bloqueia a execução concorrente, muda para `processing` e chama FastAPI.
3. FastAPI extrai texto por página. OCR é acionado somente para páginas com texto insuficiente e quando o runtime dispõe do mecanismo configurado.
4. O texto é normalizado, segmentado e indexado. A recuperação combina similaridade vetorial e busca textual.
5. O conteúdo do documento é delimitado como entrada não confiável. Instruções encontradas nele não alteram regras, ferramentas, prompts de sistema ou schemas.
6. O provedor retorna JSON estruturado. O fake usa fixtures e hashing para produzir resultados reprodutíveis.
7. FastAPI valida enum, confiança, citações e páginas antes de responder.
8. Laravel repete validações de fronteira, persiste o resultado em transação e marca `completed`.
9. Falhas recebem código sanitizado, backoff e número limitado de tentativas antes de `failed`.

O Vue acompanha o estado por polling leve. Não haverá WebSocket no MVP.

### 6.4 Revisão e decisão

O revisor inspeciona sugestão, confiança e evidência. Ele pode confirmar o achado, alterar o status, corrigir a justificativa e acrescentar observação. Toda alteração exige justificativa e cria novo registro. A decisão do fornecedor ocorre separadamente e só pode ser registrada por `owner` ou `reviewer` após a revisão dos itens obrigatórios.

### 6.5 Auditoria

Eventos sensíveis geram registros append-only com timestamp, ator, ação, tipo e UUID do alvo, resumo sanitizado e encadeamento `previous_hash`/`event_hash`. O encadeamento detecta alterações acidentais ou indevidas, mas o produto não alegará timestamp certificado, blockchain ou não repúdio jurídico.

## 7. Provedores de IA

Uma interface do processador separará geração estruturada e embeddings:

- `FakeAIProvider`: padrão local, CI e demo; determinístico e sem rede.
- `OpenAICompatibleProvider`: opcional, configurado exclusivamente por ambiente.

Nenhuma chave, prompt completo, documento ou trecho sensível será gravado em logs. O provedor recebe somente o contexto mínimo recuperado. O schema rejeita campos inesperados, status inválidos, páginas inexistentes e conclusões sem evidência. A detecção de prompt injection combina padrões explícitos, delimitação forte e testes adversariais; ela reduz risco, mas não será descrita como proteção infalível.

## 8. Interface

O Vue seguirá o Stitch fornecido como referência visual, usando a linguagem “Sovereign Compliance Interface”:

- sidebar azul-marinho;
- teal para ações humanas e operacionais;
- índigo reservado a sugestões da IA;
- fundos claros e bordas discretas;
- Manrope em títulos, Inter no corpo e JetBrains Mono em dados técnicos;
- status sempre identificado por ícone, cor e texto.

### 8.1 Telas

- Login e entrada na demo.
- Dashboard.
- Lista e detalhes de fornecedores.
- Upload de documentos.
- Editor de requisitos.
- Progresso da análise.
- Matriz de conformidade e evidências.
- Revisão humana.
- Comparação de dois fornecedores.
- Auditoria.

Cada tela terá estados de carregamento, vazio, erro, sucesso e permissão insuficiente. Desktop preserva tabelas densas e painel lateral; tablet usa drawer; mobile converte tabelas em cartões e mantém as ações essenciais. Textos do Stitch que alegam consultas, certificações ou integrações inexistentes serão removidos ou identificados como ficção da demo.

## 9. API e contratos

A API Laravel será versionada sob `/api/v1`. Recursos principais terão endpoints REST com paginação, filtros e erros em formato consistente. O contrato Laravel–FastAPI será descrito em OpenAPI e validado nas duas pontas com schemas gerados ou compartilhados por fixture canônica.

Erros públicos terão `code`, `message`, `details` seguros e `request_id`. Logs correlacionam `request_id`, `analysis_run_id` e tentativa sem incluir documento, prompt completo, token ou PII desnecessária.

## 10. Segurança

- Escopo obrigatório por tenant no servidor, policies e testes de acesso cruzado.
- RBAC por operação, não apenas por visibilidade de botão.
- Rate limits específicos para autenticação, demo, uploads e criação de análises.
- CSRF e cookies seguros com Sanctum no mesmo domínio.
- PDFs tratados como entrada hostil; nenhum script ou ação externa é executado.
- Limites de bytes, páginas, tempo de processamento, chunks e resposta do modelo.
- Token interno HMAC com rotação por ambiente, expiração e proteção contra replay.
- Jobs idempotentes, lock de concorrência e transações curtas.
- Nenhuma decisão final gerada pela IA.
- Secrets somente em variáveis de ambiente; `.env.example` contém apenas nomes e valores seguros.
- Cabeçalhos HTTP defensivos, CORS restrito e mensagens de erro sem stack trace em produção.

## 11. Estratégia de testes

O trabalho será desenvolvido em fatias verticais com TDD: teste falhando pelo motivo esperado, implementação mínima e refatoração.

### Laravel

- autenticação e Sanctum;
- isolamento entre tenants;
- RBAC;
- upload seguro e nomes gerados;
- idempotência e concorrência da análise;
- assinatura da chamada ao FastAPI;
- retry e estado de falha;
- revisão, decisão exclusivamente humana e auditoria;
- expiração exata e isolamento de duas demos.

### FastAPI

- extração por página e chunking;
- OCR condicional;
- fake determinístico;
- schema inválido;
- citação para página inexistente;
- ausência de evidência;
- prompt injection em documento;
- autenticação HMAC, expiração e replay;
- timeouts e respostas parciais.

### Vue

- componentes principais, formulários e estados visuais;
- guards de rota e visibilidade por papel;
- matriz, evidência e revisão;
- feedback de upload e progresso;
- comparação e auditoria;
- acessibilidade essencial por teclado e nome acessível.

### Jornada integrada

Playwright cobrirá: entrar na demo, abrir fornecedor, iniciar ou consultar análise, inspecionar evidência, corrigir um achado e registrar decisão humana. Contratos OpenAPI, lint, análise estática, formatação, build e health checks compõem o pipeline de CI.

## 12. Implantação e operação

`render.yaml` descreverá o web service Docker gratuito e o PostgreSQL com pgvector. O entrypoint executará migrations de forma segura, poderá popular a demo base e iniciará os processos supervisionados. Health checks distintos verificarão Laravel e FastAPI; o endpoint público refletirá indisponibilidade crítica sem expor detalhes.

O README explicará:

- pré-requisitos e início com Docker Compose;
- arquitetura e estrutura do monorepo;
- variáveis e escolha do provedor;
- credenciais e dados fictícios;
- comandos de teste, lint e build;
- deploy gratuito no Render;
- recriação do banco após 30 dias;
- cold start, limites de armazenamento e ausência de backups;
- migração futura para serviços separados e armazenamento de objetos.

O projeto apenas preparará o deploy. Nenhuma conta, cobrança, cartão ou recurso externo será criado sem autorização explícita posterior.

## 13. Etapas de implementação

1. Fundação do monorepo, containers, CI e contratos básicos.
2. Autenticação, tenants, RBAC e ciclo da demo.
3. Fornecedores, requisitos e upload seguro.
4. Fila, FastAPI, PDF, chunks, vetores e provedor fake.
5. Achados, evidências, revisão, decisão e auditoria.
6. Dashboard, comparação e acabamento responsivo conforme o Stitch.
7. Jornada E2E, hardening, imagem de produção, `render.yaml` e documentação.

## 14. Critérios de aceite

- Todo o escopo funcional listado na seção 3 está navegável e persiste conforme as regras.
- Duas organizações e duas sessões demo não conseguem observar ou alterar dados entre si.
- Um resultado inválido do processador não é persistido como análise concluída.
- Nenhuma decisão é criada por ator sem permissão ou por código de IA.
- A demonstração funciona sem chave paga e contém 2–3 fornecedores, quatro estados de achado, evidências, análise aguardando revisão, decisão concluída e auditoria.
- O projeto sobe localmente por um fluxo documentado e o container de produção passa nos health checks.
- Todas as suítes obrigatórias passam em ambiente limpo.
- O README permite a um avaliador entender, executar, testar e publicar o projeto no Render gratuito.

## 15. Referências oficiais confirmadas

- Laravel 13 e suporte de PHP: <https://laravel.com/framework/docs/releases>
- React foi substituído por Vue por decisão posterior do usuário: <https://vuejs.org/guide/quick-start.html>
- Requisito de Node do Vite: <https://vite.dev/guide/>
- Limitações do Render gratuito: <https://render.com/docs/free>
- Serviços web Docker no Render: <https://render.com/docs/web-services>
- Extensão pgvector no Render Postgres: <https://render.com/docs/postgresql-extensions>

## 16. Suposições registradas

- “Tudo gratuito” significa aceitar cold starts, armazenamento limitado e recriação mensal do PostgreSQL gratuito do Render.
- Laravel e FastAPI permanecem projetos separados no monorepo, embora compartilhem um container em produção gratuita.
- A demonstração usa somente entidades e documentos fictícios.
- O provedor fake é o padrão; o adaptador real não é necessário para executar a jornada.
- Uploads públicos são pequenos e temporários; armazenamento de objetos fica para uma etapa futura.
- Português do Brasil é o idioma inicial da interface e documentação de uso.
