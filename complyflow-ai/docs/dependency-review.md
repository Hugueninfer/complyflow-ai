# Revisão de dependências e distribuição

Consulta realizada em 14/09/2026, sem credenciais de serviços de IA. `composer audit` e `npm audit` (Vue e Playwright) retornaram zero vulnerabilidades conhecidas. A primeira auditoria Python apontou versões antigas de pypdf, Starlette, pytest e pip; runtime/test foram separados e pypdf, FastAPI/Starlette e pytest atualizados. A auditoria final de `requirements-test.lock` (que inclui runtime) retornou zero avisos. O pip é uma ferramenta de build e não está no runtime de produção.

Para repetir:

```bash
docker compose run --rm --no-deps api composer audit
docker compose run --rm --no-deps web npm audit
docker run --rm complyflow-e2e npm audit
# Num ambiente de ferramentas isolado com pip-audit instalado:
pip-audit -r services/processor/requirements-test.lock
```

O container E2E Compose tem rede interna por intenção. Sua auditoria usa `docker run` com a imagem já construída para alcançar o registry, sem liberar internet na jornada de testes Compose.

`composer licenses --format=json` declarou MIT (78), BSD-3-Clause (29), Apache-2.0 (1) e duas entradas com alternativas BSD/GPL. O conjunto inclui ferramentas de desenvolvimento; as entradas com alternativas permitem escolher BSD-3-Clause. O lock Vue declarou MIT (321), MIT-0 (2), Apache-2.0 (18), OFL-1.1 (3), ISC (15), MPL-2.0 (24), BSD-2-Clause (10), BSD-3-Clause (3), BlueOak-1.0.0 (5), CC0-1.0 (1). Contagens incluem pacotes opcionais por plataforma, não apenas código efetivamente distribuído.

Runtime Python declara MIT, BSD-3-Clause, MPL-2.0 (certifi), PSF-2.0 (typing_extensions) e MIT ou Apache-2.0 (sniffio). Arquivos de licença dos pacotes Python/PHP permanecem na distribuição. Vue, Pinia, Vue Router, Lucide e as três fontes têm seus textos originais copiados para `/licenses/*.txt` no bundle final; preserve esses avisos ao redistribuir. Debian, PHP, Python e Nginx mantêm licenças próprias, inclusive componentes do sistema com copyleft; MIT do projeto não relicencia terceiros.

Não foi realizada análise jurídica nem certificação de cadeia de suprimento. Advisories mudam, base de dados de auditoria pode ter atraso e o scanner de dependências de aplicação não cobre integralmente pacotes do sistema. Atualize/reconstrua as bases regularmente e faça scan de imagem e revisão de licenças adequados ao seu uso antes de operar com dados reais.
