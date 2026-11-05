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
# copia o install_ytlive.sh para aqui (WinSCP), ou descarrega-o:
curl -fsSL -o install_ytlive.sh https://raw.githubusercontent.com/paac5336/YouTube.XUI/main/install_ytlive.sh

head -1 install_ytlive.sh          # tem de mostrar: #!/bin/bash
sed -i 's/\r$//' install_ytlive.sh
sudo bash install_ytlive.sh
```

O instalador põe:

- PHP (`php-cli`)
- **yt-dlp** em `/usr/local/bin/yt-dlp`, atualizado todos os dias às 05:17
- **Deno** em `/usr/local/bin/deno` (motor JavaScript de que o yt-dlp precisa para o YouTube)
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

`ID_DO_CANAL` pode ser o ID `UCxxxxxxxxxxxxxxxxxxxxxx` ou o `@handle`. Usa `?c=` (canal) em vez de `?v=` (vídeo): quando o canal começa um direto novo, o link continua a funcionar.

Exemplo testado: Pai Natal 24h → `c=UCHdUmP7lMXJE9aUAtFQbJOg`

## Problemas e soluções

| O que aparece | Causa | Solução |
| --- | --- | --- |
| `Sem direto ativo` | o canal não está em direto, ou é um vídeo normal | esperar pelo direto; vídeos normais não funcionam |
| `O canal não tem nenhum direto ativo` | canal offline | o stream volta sozinho quando o canal transmitir |
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
| `/tmp/ytlive_cache/` | cache de links |

## Desinstalar

```bash
systemctl disable --now ytlive
rm -f /etc/systemd/system/ytlive.service /etc/cron.d/ytlive-ytdlp
systemctl daemon-reload
rm -rf /opt/ytlive /tmp/ytlive_cache
# opcional: rm -f /usr/local/bin/yt-dlp /usr/local/bin/deno
```

---

Baseado no script original de @BelAScripts. Usa o conteúdo do YouTube de acordo com os Termos do YouTube e os direitos dos donos dos canais.
