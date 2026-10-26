# YouTube para Streamit

[English](README.md) · [Español](README.es.md) · **Português (Brasil)**

Plugin do WordPress que **importa automaticamente os vídeos das suas playlists do YouTube como episódios do [Streamit](https://iqonic.design/)**, coloca-os dentro do programa e da temporada, e **avisa por e-mail** quando um programa chega ao episódio que você definir (por exemplo, o 13).

> Projeto independente. **Não é afiliado nem endossado pela Iqonic nem pelo Streamit.** O Streamit é um produto comercial dos seus autores; este repositório não inclui o código dele.

## O que faz

- Um programa do Streamit = uma playlist do YouTube. Cada vídeo novo vira um episódio, com título, descrição e miniatura (convertida para WebP quando o servidor consegue).
- Menu próprio **YouTube para Streamit** com sete seções: *Programas sincronizados*, *Vídeos da playlist*, *Configurações*, *Ferramentas*, *Episódios importados*, *Registro de sincronização* e *Verificação do sistema*.
- Seletor de programa **com busca** e listas **paginadas** (pensado para muitos programas).
- **Escolha quais vídeos importar**, um por um, e **detecte os episódios que você já tinha** — mesmo que o título seja diferente do do YouTube.
- Ative ou desative programas com uma caixa de seleção, ou sincronize só um manualmente.
- Lê o YouTube pela **API oficial** (recomendado) ou pelo **feed RSS** como reserva, com novas tentativas.
- **Funciona em celulares e tablets**: as tabelas viram cartões em telas pequenas.
- Disponível em **inglês, espanhol e português**.
- Salva a **duração** de cada episódio (horas:minutos) a partir da API do YouTube, e pode **preencher a duração de episódios que você já tem**.
- Ferramenta para **reparar** episódios que não ficaram vinculados ao programa.

## Requisitos

- WordPress 5.8 ou superior e PHP 7.4 ou superior.
- Plugin **Streamit 1.3.x** ativo (testado com a 1.3.9).

## Instalação

1. Baixe o ZIP `yt-to-streamit-x.y.z.zip` (seção *Releases*).
2. No WordPress: **Plugins → Adicionar novo → Enviar plugin** e ative “YouTube para Streamit”.
3. Abra **YouTube para Streamit** no menu lateral do WordPress.

## Configuração rápida

1. **Configurações:** escolha como você carrega o vídeo hoje (código embed ou URL, igual aos seus episódios atuais), seu e-mail de aviso e a frequência (a cada 12 horas por padrão). Salve.
2. **Programas sincronizados → + Adicionar programa:** busque e escolha o programa do Streamit, cole a URL ou o ID da playlist e defina o episódio em que quer o aviso.
3. Na mesma página, defina **Importar vídeos a partir de** (evita trazer todo o histórico) e **Status ao importar**. Comece em *Rascunho*. **Salvar alterações**.
4. **Verificação do sistema → Verificar + teste de criação:** tudo deve ficar verde.
5. Clique em **Sincronizar agora**. Um aviso traz o link para o **Registro de sincronização**, onde cada vídeo aparece com o que aconteceu. Revise um episódio no Streamit e, quando estiver bom, mude para *Publicado*.

A sincronização automática roda **a cada 12 horas** por padrão; você pode mudar em *Configurações → Frequência* (30 min, 1 h, 6 h, 12 h ou 24 h). Usa o WP-Cron, que só dispara quando alguém visita o site. Para importar na hora, use **Sincronizar agora**.

## Se você já tem episódios carregados

- **Detecção automática:** o plugin lê o link ou o código embed de cada episódio do Streamit e reconhece os vídeos do YouTube que já estão lá, **mesmo que o título seja diferente**. Esses vídeos são ignorados e aparecem com um link para o episódio.
- **Seleção manual:** em *Programas sincronizados*, mude a coluna **Vídeos a importar** para “Só os que eu escolher” e clique em **Escolher vídeos**. Você verá a playlist com uma caixa por vídeo. No modo “Todos os novos”, tudo o que for novo é importado, exceto o que você desmarcar.
- Se algum episódio antigo tem o vídeo **enviado como arquivo** (e não como link do YouTube), ele não pode ser reconhecido; nesse caso use o modo manual e deixe esse vídeo desmarcado.
- O que você marca manualmente tem prioridade sobre a data *Importar vídeos a partir de*.

## Chave de API do YouTube (recomendada)

O feed RSS do YouTube falha de forma intermitente (pode responder 404 por horas). Com uma chave de API gratuita, o plugin lê as playlists pela API oficial e mantém o RSS como reserva. A chave também é necessária para ler a **duração** de cada vídeo; sem ela o campo de duração fica vazio.

1. No [Google Cloud](https://console.cloud.google.com/), crie um projeto e ative a **YouTube Data API v3**.
2. Crie uma **chave de API** e restrinja-a a essa API.
3. Cole em **Configurações** ou, melhor, defina no `wp-config.php` para que não fique no banco de dados:

```php
define( 'YTS_API_KEY', 'sua-chave' );
```

## Idiomas e celular

- A interface segue o idioma do WordPress: **Configurações → Geral → Idioma do site**, ou por usuário em **Usuários → Perfil → Idioma**. Inclui inglês, espanhol (Espanha, Chile, Colômbia, México, Argentina) e português (Brasil, Portugal).
- No celular, as abas deslizam, as tabelas viram cartões, a barra de salvar fica fixa embaixo e os campos têm tamanho para toque.

## Se algo falhar

| Sintoma | O que significa |
|---|---|
| `HTTP 404` ao ler a playlist | Falha temporária do feed RSS do YouTube. Ele tenta de novo sozinho; use a chave de API para evitar. |
| `quota` no relatório | A cota diária da API esgotou (reinicia à meia-noite, horário do Pacífico dos EUA). |
| Vídeo ignorado pela “data de início” | O YouTube usa a data de publicação do vídeo, não a de adicioná-lo à playlist. Ajuste *Importar vídeos a partir de*. |
| Episódios que não aparecem no programa | **Ferramentas → Reparar vínculos**. |

Mais detalhes em [MANTENIMIENTO.md](MANTENIMIENTO.md) (guia de manutenção, em espanhol).

## Segurança

- Toda ação do painel exige permissão de administrador e nonce (formulários POST).
- A chave de API não aparece no painel depois de salva e pode ficar no `wp-config.php`.
- Os dados que vêm do YouTube (ID do vídeo, títulos, descrições) são validados e escapados antes de usar.
- Ao **excluir** o plugin, suas configurações são removidas; os episódios importados são mantidos.

## Desenvolvimento

```bash
php tests/smoke-test.php                                          # lógica do plugin, sem WordPress (158 verificações)
npm install jsdom --no-save && node tests/panel-test.js           # JavaScript do painel (42 verificações, opcional)
npm install puppeteer-core @sparticuz/chromium --no-save && node tests/mobile-test.js   # layout responsivo em um navegador real (116 verificações, opcional)
bin/update-translations.sh                                        # atualiza .pot/.po/.mo depois de mudar textos
```

Estrutura e receitas: [MANTENIMIENTO.md](MANTENIMIENTO.md). Histórico de mudanças: [CHANGELOG.md](CHANGELOG.md).

## Licença

GPL-2.0-or-later. Veja [LICENSE](LICENSE).
