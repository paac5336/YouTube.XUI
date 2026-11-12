# YouTube.XUI — diretos do YouTube como canais no XUI.one

Um direto do YouTube entra no XUI como um canal normal, com a fonte:

```
http://127.0.0.1:8088/ytlive.php?c=ID_DO_CANAL&p=1
```

desde que o script esteja instalado **no servidor que puxa o canal** e o **LLOD esteja desligado**.

> Só funciona com **diretos**. Vídeos normais não têm link HLS.

## Ficheiros

| Ficheiro | Para que serve |
| --- | --- |
| `install_ytlive.sh` | instalador completo (já traz o `ytlive.php` lá dentro) — **é este que usas** |
| `ytlive.php` | o script, para atualizar à mão em `/opt/ytlive/ytlive.php` |
| `ytlive.service` | o serviço systemd, como referência (o instalador cria-o sozinho) |

## Como funciona

1. O XUI abre `http://127.0.0.1:8088/ytlive.php?c=...&p=1` no próprio servidor.
2. O script procura o direto ativo do canal e tenta obter o link `.m3u8` por esta ordem: página web → API como app de iPhone → API como Android VR → yt-dlp.
3. O link fica em cache até 10 minutos antes de expirar (no máximo 3 h).
4. Com `&p=1`, o script devolve a lista `.m3u8` ao XUI; o ffmpeg do XUI lê os segmentos diretamente do YouTube.

O link do YouTube leva o IP do servidor que o pediu, por isso **o script tem de estar no mesmo servidor que puxa o canal** (main ou LB). Instala-o em todos os servidores a que atribuis canais do YouTube.

## 1. Instalar num servidor

```bash
cd /root
# copia o install_ytlive.sh para aqui com o WinSCP
# (o repositório é privado: o download direto por curl só funciona se o tornares público)

head -1 install_ytlive.sh          # tem de mostrar: #!/bin/bash
sed -i 's/\r$//' install_ytlive.sh
sudo bash install_ytlive.sh
```

O instalador põe:

- PHP (`php-cli`)
- **yt-dlp** em `/usr/local/bin/yt-dlp`, atualizado todos os dias às 05:17
- **Deno** em `/usr/local/bin/deno` (motor JavaScript de que o yt-dlp precisa para o YouTube)
- o comando **`ytlive-cookies`**, para instalar as cookies do YouTube (ver mais abaixo)
- o script em `/opt/ytlive/ytlive.php`
- o serviço `ytlive` na porta interna `127.0.0.1:8088`, com 4 pedidos em simultâneo e arranque automático

Pode correr-se outra vez para atualizar. Funciona em Ubuntu 20.04 e 22.04.

## 2. Testar um canal

```bash
curl "http://127.0.0.1:8088/ytlive.php?c=UCxxxxxxxxxxxxxxxxxxxxxx&debug=1"
curl "http://127.0.0.1:8088/ytlive.php?c=@NomeDoCanal&debug=1"
curl "http://127.0.0.1:8088/ytlive.php?v=ID_DO_VIDEO&debug=1"
```

`OK` + um link `googlevideo.com` = pronto. A linha `Metodo:` diz qual método funcionou. O primeiro pedido pode demorar 10–20 s.

Ver a lista que o XUI recebe:

```bash
curl -s "http://127.0.0.1:8088/ytlive.php?c=ID_DO_CANAL&p=1" | head -8
```

Teste completo com o ffmpeg do XUI (tem de correr como utilizador `xui`):

```bash
sudo -u xui /home/xui/bin/ffmpeg_bin/4.4/ffprobe -v error -show_entries stream=codec_name,width,height -of compact "http://127.0.0.1:8088/ytlive.php?c=ID_DO_CANAL&p=1"
sudo -u xui /home/xui/bin/ffmpeg_bin/4.4/ffmpeg -hide_banner -loglevel warning -t 10 -i "http://127.0.0.1:8088/ytlive.php?c=ID_DO_CANAL&p=1" -c copy -f null -
```

## 3. Criar o canal no XUI

Em **Streams → Add Stream**:

| Campo | Valor |
| --- | --- |
| Source | `http://127.0.0.1:8088/ytlive.php?c=ID_DO_CANAL&p=1` |
| Servidor | um servidor onde o script está instalado |
| Direct Source | **desligado** |
| Low Latency On-Demand (LLOD) | **desligado** |
| On-Demand | opcional (desligado arranca mais depressa) |

`ID_DO_CANAL` pode ser o ID `UCxxxxxxxxxxxxxxxxxxxxxx` ou o `@handle`.

## 4. Escolher o direto: exemplos de URL

Há três formas de dizer ao script que direto queres. Todas levam `&p=1` no fim quando são a fonte no XUI.

| Situação | Fonte no XUI | O que acontece |
| --- | --- | --- |
| Canal com **um** direto de cada vez | `http://127.0.0.1:8088/ytlive.php?c=UCHdUmP7lMXJE9aUAtFQbJOg&p=1` | apanha o direto que o canal tiver ativo; se o direto for reiniciado com um ID novo, continua a funcionar |
| O mesmo, pelo `@handle` | `http://127.0.0.1:8088/ytlive.php?c=@NomeDoCanal&p=1` | igual ao anterior |
| Canal com **vários** diretos ao mesmo tempo | `http://127.0.0.1:8088/ytlive.php?c=@NomeDoCanal&t=Polo%20Norte&p=1` | escolhe, entre os diretos ativos, o que tem **todas** as palavras no título; continua a funcionar se esse direto for reiniciado com um ID novo |
| Um direto **específico**, fixo | `http://127.0.0.1:8088/ytlive.php?v=guvwk0S17w4&p=1` | fica preso a esse vídeo; deixa de funcionar se o canal o terminar e abrir outro |

Sem `&t=`, num canal com vários diretos, quem escolhe é o YouTube (normalmente o principal ou o mais recente), e a escolha pode mudar sem aviso.

Regras do `&t=`:

- todas as palavras têm de aparecer no título, em qualquer ordem;
- maiúsculas e acentos não contam (`fabrica` encontra "Fábrica");
- os espaços escrevem-se `%20` no URL (`t=Polo%20Norte`);
- se várias transmissões servirem, fica a mais recente.

Ver os diretos ativos de um canal (para escolher as palavras do `&t=`):

```bash
curl "http://127.0.0.1:8088/ytlive.php?c=@NomeDoCanal&list=1"
```

```
2 direto(s) ativo(s):

  1. guvwk0S17w4  🔴 Papai Noel ao Vivo 24h · Fábrica do Polo Norte
  2. abcdEFGhijk  Casa do Pai Natal — câmara 2
```

Testar antes de pôr no XUI:

```bash
curl "http://127.0.0.1:8088/ytlive.php?c=@NomeDoCanal&t=Polo%20Norte&debug=1"
```

A resposta mostra `Video:` e `Titulo:` do direto escolhido. Se nenhum título servir, aparece a lista dos diretos ativos para corrigires as palavras.

Exemplo testado: Pai Natal 24h → `c=UCHdUmP7lMXJE9aUAtFQbJOg`

## Problemas e soluções

| O que aparece | Causa | Solução |
| --- | --- | --- |
| `Sem direto ativo` | o canal não está em direto, ou é um vídeo normal | esperar pelo direto; vídeos normais não funcionam |
| `O canal não tem nenhum direto ativo` | canal offline | o stream volta sozinho quando o canal transmitir |
| `Nenhum direto ativo do canal tem "..." no título` | as palavras do `&t=` não existem em nenhum título ativo | ver os títulos com `&list=1` e ajustar as palavras |
| `O YouTube bloqueou o pedido` (503) | IP do servidor marcado pelo YouTube | não atribuir canais do YouTube a esse servidor |
| `Playback on other websites has been disabled` | o dono bloqueou a reprodução fora do YouTube | já resolvido pelo script (yt-dlp com clientes web) |
| `[LLOD] Source isn't MPEG-TS` nos logs do XUI | LLOD ligado no canal | desligar o LLOD nesse canal |
| o XUI reinicia o stream a cada 2 s | erro do ffmpeg ao abrir a fonte | ver `/home/xui/content/streams/<ID>.errors` |
| `XUI.one - Please run as XUI user` | ffmpeg/ffprobe do XUI corrido como root | usar `sudo -u xui ...` |
| acentos estranhos (`v▒deo`) no PuTTY | terminal sem UTF-8 | PuTTY: Window → Translation → UTF-8 |
| `?php: No such file or directory` | ficheiros trocados ao copiar | `head -1`: o instalador começa por `#!/bin/bash` |

## Atualizar só o script

Copia o `ytlive.php` novo para `/opt/ytlive/ytlive.php` e:

```bash
sed -i 's/\r$//' /opt/ytlive/ytlive.php
chown www-data:www-data /opt/ytlive/ytlive.php
php -l /opt/ytlive/ytlive.php
rm -rf /tmp/ytlive_cache
systemctl restart ytlive
```

## Manutenção e diagnóstico

```bash
systemctl status ytlive                       # estado do serviço
journalctl -u ytlive -f                       # pedidos em tempo real ([200] = OK)
journalctl -u ytlive -n 50 --no-pager         # últimos registos
systemctl restart ytlive                      # reiniciar
rm -rf /tmp/ytlive_cache                      # limpar a cache de links
yt-dlp -U                                     # atualizar o yt-dlp à mão

# erros de um stream no XUI (troca <ID> pelo ID do stream)
tail -20 /home/xui/content/streams/<ID>.errors

# o stream está estável? (corre 2x: PID igual e tempo a subir = OK)
ps -eo pid,etimes,cmd | grep -v grep | grep "ytlive.php" | cut -c1-150
```

O servidor pode ser reiniciado: o serviço arranca sozinho. A cache em `/tmp` é apagada e refeita no primeiro pedido.

## Onde fica cada coisa

| Caminho | O quê |
| --- | --- |
| `/opt/ytlive/ytlive.php` | script |
| `/etc/systemd/system/ytlive.service` | serviço (porta 8088, só `127.0.0.1`) |
| `/usr/local/bin/yt-dlp` | yt-dlp |
| `/usr/local/bin/deno` | Deno |
| `/etc/cron.d/ytlive-ytdlp` | atualização diária do yt-dlp |
| `/usr/local/bin/ytlive-cookies` | comando para instalar as cookies |
| `/opt/ytlive/cookies.txt` | cookies do YouTube (se instaladas) |
| `/tmp/ytlive_cache/` | cache de links |

## Erro "Sign in to confirm you're not a bot" — cookies

O YouTube às vezes bloqueia o IP do VPS. O debug mostra `isLive=true`, os métodos falham e a linha do yt-dlp diz *Sign in to confirm you're not a bot*. A solução é dar ao yt-dlp as cookies de uma conta YouTube.

**1. Exportar as cookies no PC**

1. Abre uma **janela privada** do Chrome/Firefox e entra numa conta YouTube. Usa uma conta secundária, não a tua principal.
2. Com a extensão **Get cookies.txt LOCALLY** (tem de estar autorizada nas janelas privadas), exporta as cookies de `youtube.com` no formato **Netscape**. Guarda o ficheiro como `cookies.txt`.
3. **Fecha a janela privada sem sair da conta.** Se saíres, as cookies deixam de valer.

**2. Instalar no servidor**

Copia o `cookies.txt` para `/root` com o WinSCP e corre:

```bash
ytlive-cookies
```

O comando confirma que o ficheiro é válido e tem sessão iniciada, instala-o em `/opt/ytlive/cookies.txt`, limpa a cache dos links e apaga o original de `/root`. Não é preciso reiniciar nada.

**3. Testar**

```bash
curl "http://127.0.0.1:8088/ytlive.php?v=ID_DO_VIDEO&debug=1"
```

Outros comandos:

```bash
ytlive-cookies --estado      # há cookies? há quantos dias foram instaladas?
ytlive-cookies --remover     # apagar as cookies
ytlive-cookies /outro/ficheiro.txt
```

As cookies costumam durar semanas a meses (o yt-dlp vai-as renovando). Quando o erro voltar, a linha do yt-dlp no debug diz `com cookies, instaladas há N dia(s)`: exporta outra vez e corre `ytlive-cookies`. Instala as cookies em cada servidor que puxa canais do YouTube.

## Desinstalar

```bash
systemctl disable --now ytlive
rm -f /etc/systemd/system/ytlive.service /etc/cron.d/ytlive-ytdlp /usr/local/bin/ytlive-cookies
systemctl daemon-reload
rm -rf /opt/ytlive /tmp/ytlive_cache
# opcional: rm -f /usr/local/bin/yt-dlp /usr/local/bin/deno
```

---

Baseado no script original de @BelAScripts. Usa o conteúdo do YouTube de acordo com os Termos do YouTube e os direitos dos donos dos canais.
