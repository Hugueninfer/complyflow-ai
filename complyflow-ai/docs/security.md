# Segurança e limites de confiança

O MVP foi desenhado para demonstração fictícia e revisão humana. Não é uma certificação, parecer jurídico ou substituto de controles operacionais para dados reais.

## Controles implementados

Laravel resolve o tenant a partir da sessão autenticada; UUID enviado pelo cliente não concede acesso. Models, queries, policies e serviços de escrita conferem organização e permissões. Os papéis owner, analyst e reviewer distinguem cadastro/análise de revisão/decisão. A demo cria um reviewer isolado e expira exatamente 24 horas após a criação, incluindo bloqueio de sessões vencidas no servidor.

Mutações usam CSRF e cookie de sessão regenerado no login. Produção configura cookies Secure/HttpOnly/SameSite=Lax, mesma origem e debug desativado. Nginx impede acesso a arquivos ocultos/PHP arbitrário, envia CSP, nosniff, DENY e política de permissões; fontes e scripts vêm do próprio domínio. Cadastro exige senha de 12 caracteres e confirmação. Não há recuperação de senha, MFA ou verificação de e-mail.

### Limites públicos e proxy reverso

Os contadores ficam no PostgreSQL. Login limita 5 requisições/minuto por e-mail normalizado (`trim` + minúsculas), armazenado como HMAC, e 20/minuto por sessão. Cadastro limita 10/minuto por identidade e por sessão. Requisições sem e-mail usam a sessão, não um bucket anônimo compartilhado. A demo limita 10/minuto por nonce aleatório criado **no servidor**, armazenado na sessão e preservado ao regenerar o ID no login; criar outro usuário demo não reinicia o orçamento. O cookie de sessão é autenticado/cifrado pelo Laravel. Corpo, Origin e X-Forwarded-For não escolhem esse nonce. Há ainda teto agregado de 60 demos/minuto para limitar rotação de cookies; esgotar o limite de um visitante não bloqueia os demais, mas um ataque distribuído pode esgotar o teto agregado. Upload/análise mantêm 30/minuto por usuário autenticado.

Não inferimos IP real a partir do load balancer do Render: sem uma política verificável de CIDRs e de sobrescrita dos cabeçalhos, confiar em `*` ou em XFF recebido permitiria falsificação. Não há proxies Laravel confiáveis configurados. O Nginx local passa diretamente ao FPM loopback os parâmetros CGI de scheme, host e porta derivados de `APP_URL`, validada no startup. Ele descarta `Forwarded`, `X-Forwarded-*` relevantes e `X-Real-IP`, e sobrescreve Host; o visitante não altera a origem usada em URLs. HTTPS externo funciona mesmo com HTTP entre o balanceador e Nginx. O endereço registrado continua sendo o peer de transporte, **não evidência do IP do visitante**. O serviço oferece uma origem canônica por deploy; um domínio alternativo deve ser configurado via `APP_URL` e novo deploy.

Limites por identidade têm o tradeoff de um atacante poder temporariamente esgotar o orçamento de uma identidade conhecida; rotação de cookies permite novos orçamentos de sessão. Eles mitigam abuso, não substituem CAPTCHA, verificação de e-mail ou proteção na borda com identidade de cliente confiável. A ausência de IP real não reduz checagens de tenant, autenticação ou CSRF (401/419).

O upload verifica permissão, MIME/assinatura PDF, tamanho de 5 MiB, quantidade, cotas e hash. Nomes são gerados no servidor. Não existe endpoint de execução/download público de conteúdo hostil. Os binários ficam no PostgreSQL; arquivos temporários não são fonte de persistência. Parsing ocorre em subprocesso Linux com limites de memória, CPU, tempo de relógio, páginas e texto, e limpeza do grupo de processos. OCR não roda na imagem gratuita.

O processador aceita apenas chamadas assinadas com HMAC-SHA256 dos bytes originais, timestamp dentro de 60 segundos e nonce não repetido durante 120 segundos. Não habilita CORS e escuta loopback na produção. Schema fechado, checagem de IDs e correspondência literal de citações ocorrem nas duas aplicações. PDFs são entrada não confiável; heurísticas de prompt injection só sinalizam suspeitas. Elas não são uma proteção infalível.

Revisões e decisões são append-only, com autor, justificativa e idempotência. Decisões exigem papel/permissão, análise e checklist vigentes e revisão obrigatória concluída. A IA não chama essas operações. Triggers e modelos impedem update/delete de histórico ativo; a exceção controlada de retenção é a purga de demos vencidas. Auditoria encadeia hashes, mas um administrador com domínio do banco pode reescrever toda a cadeia ou truncar seu fim sem uma âncora externa. A verificação na interface cobre a página consultada e sua fronteira, não toda a história.

## Segredos e configuração

`.env` não é versionado nem enviado ao build; não passe segredos via build args. Render gera `APP_KEY_MATERIAL` e `PROCESSOR_HMAC_SECRET`; a conexão vem do banco associado. O entrypoint deriva a APP_KEY sem imprimi-la e cria caches somente em runtime. Conserve o material entre deploys: trocá-lo invalida sessões e dados cifrados. O banco gratuito é configurado sem acesso externo (`ipAllowList: []`). O serviço roda como UID10001; somente cache/storage/tmp são graváveis.

O provedor fake padrão não envia documentos para terceiros. Para usar o adaptador `openai-compatible`, é preciso configurar explicitamente `AI_PROVIDER`, `AI_BASE_URL`, `AI_MODEL` e `AI_API_KEY` e avaliar o destino, contratos e retenção do operador escolhido. Use HTTPS e um endpoint confiável. Somente contexto recuperado é enviado, mas esse contexto pode conter informação sensível. Nenhuma chave é necessária para avaliar a demo.

## Riscos e evolução

O nonce store **do protocolo HMAC do processador** é em memória e reinicia com o processo; antes de múltiplas réplicas/UVicorn workers, implemente cache atômico compartilhado. Ele é independente do nonce de rate-limit da sessão, que fica no PostgreSQL. PHP/Python no mesmo container e a cota de 512 MB não isolam carga hostil de visitantes concorrentes. Os testes não substituem pentest, monitoramento ou dimensionamento. Endpoints públicos de cadastro/demo ainda não têm CAPTCHA; o teto agregado de demo é um limite por minuto, não um orçamento de armazenamento acumulado por workspace. Use somente PDFs fictícios no host gratuito.

A exclusão física de demos acontece no startup e pelo comando `demo:purge-expired`; não há cron gratuito configurado. Sessões expiradas deixam de acessar dados no prazo, mas bytes podem permanecer até a próxima purga. Operação contínua necessita agendamento e política de retenção/backups. Outbox e reconciliação de reservas interrompidas permanecem no roadmap.

Dependências PHP/Node têm lockfiles e Python tem locks de runtime/test separados. A CI consulta advisories atuais; ausência de avisos conhecidos não prova ausência de vulnerabilidades. Tags das imagens base recebem atualizações upstream e devem ser reconstruídas/reavaliadas periodicamente. Pacotes de sistema têm ciclo próprio de correção.

Para reportar um problema, envie um relato privado ao responsável pelo repositório com versão e passos mínimos usando dados fictícios. Não publique tokens, documentos de terceiros ou um exploit contra serviços alheios numa issue.
