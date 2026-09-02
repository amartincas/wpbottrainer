# Despliegue — VPS compartido de WhatFunnels

Este documento no existía hasta ahora — `docker-compose.whatfunnels.yml` lo
referenciaba desde el primer commit del proyecto (Hitos 1-7), pero el
documento en sí nunca se creó. Esa ausencia es la causa raíz documentada en
`docs/DECISIONS.md` D035: sin este archivo, no había ningún lugar donde
quedara escrito que un rebuild en este VPS **siempre** requiere los dos
archivos de compose juntos — y un deploy legítimo terminó rompiendo el
webhook de Meta/WhatsApp durante casi 26 horas sin que nada lo detectara.

## Comando de rebuild (el único correcto en este VPS)

```bash
cd /opt/whatfunnels/apps/wpbottrainer/staging
git fetch origin
git reset --hard origin/master
docker compose -f docker-compose.yml -f docker-compose.whatfunnels.yml up -d --build
```

**Nunca** `docker compose up -d --build` a secas en este VPS — ver la
sección "Por qué" más abajo.

## Por qué dos archivos

Este VPS (`server1.whatfunnels.com`) corre **Nginx Proxy Manager (NPM)**
como único punto de entrada público (puertos 80/443/81), compartido con
otros proyectos (`wpbotreserva`, `soat-digital`). `docker-compose.yml` es
genérico — no sabe nada de NPM ni de este VPS en particular, para poder
levantarse igual en cualquier entorno. `docker-compose.whatfunnels.yml` es
el overlay **específico de este VPS**: une el contenedor `nginx` a la red
Docker externa `whatfunnels-edge`, la misma red donde vive NPM, para que
NPM pueda alcanzarlo por nombre de contenedor (`wpbottrainer-nginx-1`) y
enrutar `botrainer.whatfunnels.com` hacia él con su propio certificado TLS.

Sin el overlay, Docker Compose solo conecta `nginx` a la red por defecto
del proyecto (`wpbottrainer_default`) — NPM no puede resolver ni alcanzar
el contenedor por ese nombre, y cualquier petición externa (incluido el
webhook de Meta) recibe `502 Bad Gateway` **desde NPM, antes de llegar a
Laravel**. La app en sí sigue perfectamente sana (`/up`, healthchecks, todo
en verde) porque esos chequeos entran por el puerto publicado del host
(`:8082`), nunca por NPM — por eso el corte pasó completamente
desapercibido en la verificación post-deploy de Hito 8.3/8.4.

## Verificación post-deploy obligatoria (4 pasos, siempre los 4)

Los dos primeros ya eran práctica habitual pero **no bastan por sí solos**
— ambos entran por el puerto publicado del host (`:8082`), nunca por NPM,
así que ninguno de los dos detecta el tipo de corte de D035. Los pasos 3 y
4 son los que sí lo detectan y son obligatorios en todo rebuild de este VPS:

```bash
# 1. Servicios healthy
docker compose -f docker-compose.yml -f docker-compose.whatfunnels.yml ps
# Esperado: app, mariadb, nginx, queue → "healthy"

# 2. /up responde 200
curl -sS -o /dev/null -w 'HTTP %{http_code}\n' http://localhost:8082/up
# Esperado: HTTP 200

# 3. nginx conectado a AMBAS redes (whatfunnels-edge y wpbottrainer_default)
docker inspect wpbottrainer-nginx-1 --format '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}'
# Esperado: "whatfunnels-edge wpbottrainer_default" (las dos, sin importar el orden)

# 4. Webhook externo funcionando — a través del dominio público (NPM), no del puerto del host
curl -i "https://botrainer.whatfunnels.com/api/whatsapp/webhook/<wa_verify_token>?hub.mode=subscribe&hub.verify_token=<wa_verify_token>&hub.challenge=probe123"
# Esperado: 200 OK, cuerpo "probe123" (texto plano). Un 502 significa que falta la red del paso 3.
```

Los pasos 1-2 en verde y el paso 3-4 en rojo es exactamente lo que ocurrió
en el incidente de D035 — por eso ningún deploy se considera verificado
sin los 4.

## Otros proyectos en este mismo VPS

`wpbotreserva` y `soat-digital` usan exactamente el mismo patrón
(`docker-compose.whatfunnels.yml` propio + el mismo comando de dos
archivos) — si se crea un proyecto nuevo en este VPS, replicar ese overlay
en vez de inventar uno nuevo.
