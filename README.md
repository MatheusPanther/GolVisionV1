# GoalVision AI

GoalVision AI e um MVP SaaS em PHP + MySQL para analise probabilistica de jogos de futebol com apoio de IA.

O produto foi desenhado para parecer uma ferramenta de inteligencia esportiva, nao uma casa de aposta.

## Stack

- PHP 8.1+ recomendado
- MySQL 8+
- PDO MySQL
- cURL
- Apache com mod_rewrite
- SportMonks Football API
- OpenAI API

## Funcionalidades do MVP

- Landing page comercial
- Login e cadastro com aceite de termos e confirmacao 18+
- Dashboard com jogos do dia e filtros
- Pagina de partida com estatisticas, eventos, tendencias e analise da IA
- Bilhete Inteligente com cenarios informativos
- Historico com leitura de desempenho por mercado, liga e risco
- Configuracoes de perfil e preferencias
- Admin com ligas, jogos importados, regeneracao de analise, erros de API e uso da OpenAI
- Cache simples em arquivo para fixtures
- Limite por plano
  - Free: 3 analises por dia
  - Beta: ilimitado + slip builder
  - Pro: historico avancado + alertas futuros

## Estrutura

```text
app/
  Controllers/
  Core/
  Repositories/
  Services/
  Support/
  Views/
config/
database/
public/
storage/
index.php
.htaccess
```

## Como instalar dependencias

Este projeto nao usa Composer no MVP atual. Ele roda com PHP puro.

Voce precisa apenas garantir no servidor:

- PHP 8.1 ou superior
- extensoes `pdo_mysql` e `curl`
- Apache com `mod_rewrite`

## Como configurar o `.env`

1. Copie o arquivo de exemplo:

```bash
cp .env.example .env
```

2. Preencha as variaveis:

```env
APP_NAME="GoalVision AI"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seudominio.com.br
APP_KEY=troque-esta-chave

DB_HOST=localhost
DB_PORT=3306
DB_NAME=goalvision_ai
DB_USER=goalvision_user
DB_PASS=sua_senha_mysql
DB_CHARSET=utf8mb4

OPENAI_API_KEY=
OPENAI_MODEL=gpt-4.1-mini

SPORTMONKS_API_KEY=
SPORTMONKS_BASE_URL=https://api.sportmonks.com/v3/football

ADMIN_EMAILS=seuemail@dominio.com
APP_TIMEZONE=America/Maceio
```

Observacao para Hostinger:

- `DB_HOST`, `DB_NAME`, `DB_USER` e `DB_PASS` devem ser exatamente os dados do banco MySQL criado no hPanel
- em muitos planos da Hostinger o banco e o usuario ficam com prefixo como `u123456789_nome`

## Como rodar o banco / migrations

O schema inicial esta em:

```text
database/schema.sql
```

Voce pode importar de duas formas.

Via terminal:

```bash
mysql -u SEU_USUARIO -p SEU_BANCO < database/schema.sql
```

Via Hostinger / phpMyAdmin:

1. Abra o banco MySQL
2. Entre no phpMyAdmin
3. Clique em `Importar`
4. Selecione `database/schema.sql`
5. Execute a importacao

## Como rodar o servidor de desenvolvimento

Se voce tiver PHP instalado localmente:

```bash
php -S 127.0.0.1:8000 index.php
```

Depois abra:

```text
http://127.0.0.1:8000
```

## Como testar a SportMonks

1. Configure `SPORTMONKS_API_KEY`
2. Rode a aplicacao
3. Teste o endpoint:

```bash
curl "http://127.0.0.1:8000/api/football/fixtures?date=2026-05-23"
```

Outros endpoints:

```text
GET /api/football/live
GET /api/football/match/{fixtureId}
```

Exemplo direto da SportMonks para head-to-head:

```text
GET https://api.sportmonks.com/v3/football/fixtures/head-to-head/3468/13258?include=participants;league;league.country;state;scores;venue;events&api_token=SEU_TOKEN
```

Internamente o projeto agora usa principalmente estes recursos da SportMonks:

- `GET /fixtures/date/{date}`
- `GET /livescores/inplay`
- `GET /fixtures/{id}`
- `GET /fixtures/{id}?include=participants;league;league.country;venue;state;scores;events.type;events.period;events.player;statistics.type;sidelined.sideline.player;sidelined.sideline.type;weatherReport;predictions.type;xGFixture.type;lineups.player;lineups.xGlineup.type;lineups.details.type`
- `GET /predictions/probabilities/fixtures/{id}?include=type`
- `GET /fixtures/head-to-head/{teamA}/{teamB}`
- `GET /players/{id}?include=trophies.league;trophies.season;trophies.trophy;trophies.team;teams.team;statistics.details.type;statistics.team;statistics.season.league;latest.fixture.participants;latest.fixture.league;latest.fixture.scores;latest.details.type;nationality;detailedPosition;metadata.type`
- `GET /schedules/teams/{teamId}?include=league;league.country`
- `GET /rounds/{roundId}?include=fixtures.odds.market;fixtures.odds.bookmaker;fixtures.participants;league.country&filters=markets:1;bookmakers:2`
- `GET /teams/seasons/{seasonId}?include=statistics.details.type&filters=teamstatisticSeasons:{seasonId}`
- `GET /teams/{id}?include=latest.participants;latest.scores;latest.state`

Para livescore ao vivo, o projeto usa o endpoint com includes de contexto de partida:

- `GET /livescores/inplay?include=participants;scores;periods;events;league.country;round;state`

Com isso o endpoint interno `/api/football/live` e o dashboard do dia conseguem mostrar:

- placar atualizado
- cronometro e periodo atual
- rodada
- ultimo evento relevante da partida

Com o endpoint de rodada com odds, a analise tambem recebe:

- odds 1X2 do jogo atual
- leitura de favoritismo e equilibrio do mercado
- comparacao do jogo com os outros confrontos da mesma rodada
- panorama dos favoritos mais fortes e dos jogos mais equilibrados da rodada

Com o fixture detalhado de xG e lineups, a analise agora tambem recebe:

- xG, xGoT, xPTS e diferenca de xG por equipe
- placar resumido por periodo quando esse recorte estiver disponivel
- contagem de eventos-chave como cartoes, substituicoes e VAR
- destaques individuais por rating e por presenca ofensiva via xG da lineup
- formacao inferida a partir do XI inicial quando a API retorna lineups detalhadas

Com a expansao de match centre, prediction model e player profile, a analise tambem passa a receber:

- estatisticas da propria partida dentro do `fixture_context`
- weather report e resumo de sidelined por equipe
- probabilidades do modelo da SportMonks por mercado quando disponiveis
- perfis resumidos dos jogadores-chave da partida com posicao, pe preferido, temporada atual e ultimo jogo

Com o calendario por time, a pagina da partida e a IA tambem recebem:

- sequencia recente e proximos compromissos de cada time
- dias de descanso antes e depois do jogo analisado
- contagem de jogos na janela curta de 14 dias
- distribuicao do calendario por competicoes para detectar desgaste

Com esse endpoint de temporada, a aplicacao monta um contexto mais rico para a IA:

- estatisticas completas do time na temporada
- metricas avancadas resumidas
- comparativos de liga e ranking do time no recorte da temporada
- refresh automatico dos dados salvos quando o formato antigo estiver sem esse contexto

Se a chave nao estiver configurada, o sistema usa fallback demo para nao travar o MVP.

## Como testar a OpenAI

1. Configure `OPENAI_API_KEY`
2. Ajuste `OPENAI_MODEL`
3. Crie uma conta pela tela `/login`
4. Entre no dashboard
5. Abra uma partida
6. Clique em `Gerar analise`

Fluxo esperado:

- a partida e sincronizada
- os dados do jogo e dos times sao consolidados
- a analise estruturada e salva em `match_analyses`
- o uso da OpenAI e logado em `openai_usage_logs`

O endpoint interno correspondente e:

```text
POST /api/ai/analyze-match
```

Body:

```json
{
  "fixtureId": 910001
}
```

Observacao:

- esse endpoint faz mais sentido autenticado, porque o MVP aplica limite por plano para uso interativo
- se `OPENAI_API_KEY` nao estiver configurada, o sistema cai em uma analise fallback deterministica

## Como testar o Bilhete Inteligente

1. Entre com um usuario Beta ou Pro, ou ajuste manualmente o campo `plan` do usuario no MySQL
2. Abra `/dashboard/slip-builder`
3. Escolha perfil de risco, foco de mercado e numero de selecoes
4. Gere o cenario

Resultado:

- selecoes sugeridas
- confianca
- risco
- justificativa
- alerta de uso responsavel

## Como fazer deploy na Vercel

Este rebuild foi feito para PHP + MySQL em hospedagem tradicional. Portanto, o deploy principal recomendado agora e Hostinger, nao Vercel.

Se voce quiser manter compatibilidade futura com outro ambiente PHP, prefira um host com:

- Apache ou Nginx
- PHP 8.1+
- MySQL
- suporte a variaveis de ambiente

## Como fazer deploy na Hostinger

1. Crie o banco MySQL no hPanel
2. Importe `database/schema.sql`
3. Envie os arquivos do projeto para o diretório do site
4. Garanta que os arquivos `index.php` e `.htaccess` da raiz tambem foram enviados
5. Crie o arquivo `.env` com suas credenciais reais
6. Verifique se `storage/cache` e `storage/logs` podem ser escritos pelo PHP
7. Configure `ADMIN_EMAILS` com o email que tera acesso ao `/admin`
8. Acesse o site e crie a primeira conta

Notas de hospedagem:

- o projeto ja possui `index.php` e `.htaccess` na raiz para funcionar melhor em hospedagem compartilhada
- a pasta `public/assets` e exposta via rewrite para `/assets/...`

## Banco de dados

Tabelas principais:

- `users`
- `user_preferences`
- `leagues`
- `teams`
- `matches`
- `team_stats`
- `match_analyses`
- `slip_suggestions`
- `analysis_results`
- `openai_usage_logs`
- `api_error_logs`

## Regras legais e de produto

Este MVP foi construído com as seguintes salvaguardas:

- nao opera apostas
- nao recebe apostas
- nao processa depositos
- nao promete lucro
- nao usa linguagem como "garantido", "aposta certa", "green garantido" ou "renda garantida"
- exibe avisos 18+ e disclaimers em telas sensiveis
- nao sugere stake
- nao sugere martingale
- nao sugere recuperacao de loss

## Observacoes de validacao

No ambiente atual de desenvolvimento em que este rebuild foi montado, o binario `php` nao estava instalado. Por isso:

- a revisao estrutural do codigo foi feita arquivo a arquivo
- o projeto foi organizado para Hostinger
- a validacao por execucao real do servidor PHP ainda depende de uma maquina com PHP disponivel

Assim que voce tiver PHP local ou fizer o primeiro deploy na Hostinger, o proximo passo ideal e:

1. validar login/cadastro
2. testar importacao de fixtures
3. gerar uma analise
4. validar o slip builder
5. revisar o painel admin
