# Servidor: staging y producción

Cómo corre Pintech OS en el VPS y cómo se opera. Staging y producción viven en el mismo servidor, aislados: cada uno es
un proyecto de Docker Compose aparte, con su base, sus archivos, su túnel y sus límites de recursos.

## Qué hay en el servidor

```text
/usr/local/bin/pintech-deploy      despliega un digest (lo único que puede correr el usuario `deploy` del CI)
/usr/local/bin/pintech-backup      copia diaria de la base y de storage
/etc/sudoers.d/pintech-deploy      sudoers.example
/etc/cron.d/pintech-backup         pintech-backup.cron
/home/deploy/.ssh/authorized_keys  authorized_keys.example

/srv/pintech/<entorno>/            staging o prod (dueño root, permisos 700)
  compose.yaml                     copia de compose.prod.yaml del repositorio
  .env                             server.env.example completado (permisos 600)
  tunnel.env                       TUNNEL_TOKEN=<token del túnel de ese entorno> (permisos 600)
  deploys.log                      un renglón por despliegue: fecha, digest, commit y resultado
  last-deploy.log                  salida completa del último despliegue
  backups/                         daily/, weekly/ (solo prod) y predeploy/ (solo prod)
```

Nada se compila en el servidor: la imagen la compila el CI una vez (workflow `cd`) y se baja de GHCR por digest. El VPS
necesita un token de **solo lectura** de GHCR (`sudo docker login ghcr.io`).

`compose.yaml` y los scripts no se actualizan con un despliegue: si cambian en el repositorio, se copian a mano al
servidor.

## Desplegar

Es automático, con el workflow `cd` de GitHub Actions:

| Merge a | Qué hace |
|---|---|
| `develop` | Pruebas del commit → imagen → Trivy (avisa, no frena) → staging |
| `main` | Pruebas → la imagen del mismo árbol de archivos, sin recompilar → Trivy (frena) → espera tu aprobación → producción |
| `main` sin imagen de ese árbol (hotfix) | Pruebas → compila → Trivy (frena) → staging → espera tu aprobación → producción |

**Aprobar producción:** Actions → la corrida de `cd` → «Review deployments» → `production` → «Approve and deploy».

Si dos merges a `develop` corren a la vez, despliega solo el último: el más viejo lo avisa y deja staging como está.

**Después de un hotfix,** el arreglo tiene que volver a `develop` con otro PR. Si no, el siguiente merge a `develop`
despliega una versión sin el arreglo y lo borra de staging.

Los dos entornos de GitHub limitan desde dónde se despliega: `staging`, desde `develop` y `main` (`main` por el
hotfix); `production`, solo desde `main`, con aprobación obligatoria. Cada uno guarda sus secretos: `DEPLOY_SSH_KEY`,
`DEPLOY_KNOWN_HOSTS` y `DEPLOY_HOST`.

**Qué se ve en GitHub:** solo las líneas propias de `pintech-deploy`, porque los registros de un repositorio público los
puede leer cualquiera. El resultado sale en el resumen de la corrida: `ok-con-avisos` como advertencia, un fallo como
error (y GitHub avisa por correo). La salida completa queda en `/srv/pintech/<entorno>/last-deploy.log`:

```bash
sudo tail -n 100 /srv/pintech/staging/last-deploy.log
```

### Qué hace `pintech-deploy`

Baja la imagen, migra con la imagen nueva en un contenedor de un solo uso (los contenedores actuales siguen atendiendo),
sincroniza roles y permisos, reemplaza web, queue y scheduler, y espera su revisión de salud. Si `roles:audit` encuentra
un rol personalizado mal armado, el despliegue sigue y lo deja como ADVERTENCIA en la salida y como `ok-con-avisos` en
`deploys.log`: se corrige desde la pantalla de roles. Si la migración falla, no reemplaza nada. Si la imagen nueva no
queda sana en 90 segundos, vuelve sola a la anterior. En producción hace además una copia de la base antes de migrar. Si
se corta la conexión con GitHub, el despliegue termina igual en el servidor.

### Desplegar a mano y volver atrás

Volver atrás es desplegar el digest anterior. Solo vuelve el código: la base no se deshace. Por eso las migraciones son
siempre compatibles hacia atrás: agregar columnas o tablas; para borrar o renombrar, primero se deja de usar en un
despliegue y se borra en otro posterior.

- **Desde GitHub:** Actions → la corrida de `cd` del commit al que se quiere volver → volver a lanzar el job
  «desplegar en staging» o «desplegar en producción» (producción pide otra vez la aprobación).
- **En el servidor,** con el digest que está en `deploys.log`:

```bash
sudo tail -n 5 /srv/pintech/staging/deploys.log
sudo pintech-deploy staging sha256:<digest>
```

## Comandos sueltos

La carpeta del entorno es solo de root, así que `andres` no puede entrar con `cd`. Con `--project-directory`,
`docker compose` sabe qué proyecto, archivo e imagen usar (del `.env`):

```bash
sudo docker compose --project-directory /srv/pintech/staging ps                       # estado y salud de cada servicio
sudo docker compose --project-directory /srv/pintech/staging logs --tail=100 web      # registros (también queue, scheduler, postgres, tunnel)
sudo docker compose --project-directory /srv/pintech/staging run --rm web php artisan users:create-admin   # primer usuario (pide los datos)
sudo docker compose --project-directory /srv/pintech/staging run --rm web php artisan db:seed --force      # datos de demostración, solo staging y una vez
```

El primer usuario, los datos de demostración y el reset de staging se explican en el README principal («Entornos
protegidos»).

## Copias de seguridad

`pintech-backup` corre todos los días a las 02:00 de Colombia (`pintech-backup.cron`) y guarda en
`/srv/pintech/<entorno>/backups/`:

| | Qué | Retención |
|---|---|---|
| `daily/db-<fecha>.dump` | La base completa (`pg_dump`, formato personalizado), comprobada con `pg_restore --list` | staging 3, prod 14 |
| `daily/storage-<fecha>.tar.gz` | `storage`: archivos subidos, PDFs generados y logs (sin cachés ni sesiones) | staging 3, prod 14 |
| `weekly/` | La copia del domingo | prod 8 |
| `predeploy/db-<fecha>.dump` | La base justo antes de migrar en un despliegue | prod 5 |

A mano: `sudo pintech-backup staging`. El registro queda en `/var/log/pintech-backup.log`.

Estas copias viven en el mismo VPS: si se pierde el servidor, se pierden con él. Antes de producción se agrega una
copia cifrada fuera del VPS.

### Restaurar

Un backup sin restaurar no es un backup: se prueba una vez al mes. Con la app detenida para que nadie escriba durante
la restauración (el ejemplo es staging; en producción, igual con `prod`).

Las copias también son solo de root: se leen con `sudo cat` y se pasan por una tubería, porque un `< archivo` lo abriría
`andres`, que no tiene permiso.

```bash
PROJECT=/srv/pintech/staging
sudo ls "$PROJECT/backups/daily/"
sudo docker compose --project-directory "$PROJECT" stop web queue scheduler

# 1. Base: se borra y se crea vacía, y se carga la copia. Queda exactamente como estaba en la fecha de la copia.
sudo docker compose --project-directory "$PROJECT" exec -T postgres sh -c 'dropdb -U "$POSTGRES_USER" --force "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" "$POSTGRES_DB"'
sudo cat "$PROJECT/backups/daily/db-<fecha>.dump" \
  | sudo docker compose --project-directory "$PROJECT" exec -T postgres sh -c 'pg_restore --no-owner -U "$POSTGRES_USER" -d "$POSTGRES_DB"'

# 2. Archivos: se descomprimen sobre el volumen de storage.
sudo cat "$PROJECT/backups/daily/storage-<fecha>.tar.gz" \
  | sudo docker compose --project-directory "$PROJECT" run --rm --no-deps -T --entrypoint tar web -C /var/www/html -xzf -

# 3. Si la imagen que corre es más nueva que la copia, aplica las migraciones que le faltan a la base restaurada.
sudo docker compose --project-directory "$PROJECT" run --rm web php artisan migrate --force

sudo docker compose --project-directory "$PROJECT" up -d --wait
```

Comprobar: la app abre, se inicia sesión y se descarga un PDF que ya existía.

## Usuario `deploy` (una sola vez)

El usuario con el que GitHub entra al servidor. Tiene una llave por entorno, y cada llave solo puede correr
`pintech-deploy` en su entorno: sin terminal, sin reenvíos y sin copiar archivos. `sudo` no le permite nada más, y no
está en el grupo `docker`, porque ese grupo equivale a ser root. Aunque alguien robara esa llave, lo único que podría
hacer es desplegar una imagen que ya esté en GHCR.

Los bloques marcados **Mac** van en la carpeta del proyecto; los marcados **servidor**, después de `ssh contabo`.
Ejemplo para staging; producción, al final.

**1. Mac: llevar los archivos.** Usa los del repositorio, en una rama que ya traiga este PR.

```bash
ssh contabo 'mkdir -p ~/pintech-install'
scp .docker/server/pintech-deploy .docker/server/sudoers.example contabo:~/pintech-install/
```

**2. Servidor: actualizar el script, crear el usuario y darle permiso solo sobre el script.** `useradd` lo crea sin
contraseña: solo podrá entrar con llave. Un archivo de `sudoers` dañado puede dejarte sin `sudo`: por eso `visudo -cf`
lo comprueba antes de instalarlo y el `&&` no sigue si falla. No afecta a los contenedores que están corriendo.

```bash
cd ~/pintech-install
sudo install -o root -g root -m 755 pintech-deploy /usr/local/bin/
sudo useradd --create-home --shell /bin/bash deploy
sudo visudo -cf sudoers.example && sudo install -o root -g root -m 440 sudoers.example /etc/sudoers.d/pintech-deploy
sudo visudo -c
```

Comprobación:

```bash
id deploy                                              # solo el grupo deploy
sudo -u deploy sudo -n /usr/local/bin/pintech-deploy   # «Uso: pintech-deploy…»: llega al script y no despliega nada
sudo -u deploy sudo -n /bin/ls /root                   # «sudo: a password is required»: nada más le está permitido
```

**3. Servidor: dejarlo entrar por SSH.** Hoy `AllowUsers` solo admite a `andres`. Con **una segunda terminal abierta**,
por si acaso: `sshd -t` valida antes de reiniciar SSH, y reiniciarlo no corta las sesiones abiertas.

```bash
sudo sed -i 's/^AllowUsers andres$/AllowUsers andres deploy/' /etc/ssh/sshd_config.d/00-pintech.conf
sudo sshd -t && sudo systemctl restart ssh
sudo sshd -T | grep -i '^allowusers'
```

Tiene que mostrar `andres` y `deploy`. En una terminal **nueva**, `ssh contabo` tiene que seguir entrando. Si no entra,
en la segunda terminal se devuelve el cambio (ver «Cómo se deshace»).

**4. Mac: generar la llave de staging y guardar los secretos en GitHub.** La llave privada va directo al entorno de
GitHub y se borra del Mac: no se puede volver a leer, ni hace falta (si se pierde, se genera otra). La huella del
servidor se lee por tu conexión SSH de confianza, nunca con `ssh-keyscan`, que le creería a cualquiera que suplante al
servidor. La IP también va como secreto, porque el túnel la oculta y los registros son públicos.

```bash
REPO=Pintech-Colombia/bepro-gestion-planta-pintech
HOST="$(ssh -G contabo | awk '/^hostname /{print $2}')"
KEYDIR="$(mktemp -d)"
ssh-keygen -q -t ed25519 -N '' -C pintech-deploy-staging -f "$KEYDIR/deploy"
gh secret set DEPLOY_SSH_KEY --env staging --repo "$REPO" < "$KEYDIR/deploy"
ssh contabo 'cat > ~/deploy-staging.pub' < "$KEYDIR/deploy.pub"
rm -rf "$KEYDIR"
printf '%s' "$HOST" | gh secret set DEPLOY_HOST --env staging --repo "$REPO"
ssh contabo 'cat /etc/ssh/ssh_host_ed25519_key.pub' | awk -v h="$HOST" '{print h, $1, $2}' \
  | gh secret set DEPLOY_KNOWN_HOSTS --env staging --repo "$REPO"
gh secret list --env staging --repo "$REPO"
```

Tiene que listar los tres secretos.

**5. Servidor: autorizar la llave, limitada a un comando** (`authorized_keys.example`).

```bash
sudo install -d -o deploy -g deploy -m 700 /home/deploy/.ssh
printf 'restrict,command="sudo -n /usr/local/bin/pintech-deploy staging $SSH_ORIGINAL_COMMAND" %s\n' "$(cat ~/deploy-staging.pub)" \
  | sudo tee /home/deploy/.ssh/authorized_keys > /dev/null
sudo chown deploy:deploy /home/deploy/.ssh/authorized_keys
sudo chmod 600 /home/deploy/.ssh/authorized_keys
rm -r ~/deploy-staging.pub ~/pintech-install
sudo cut -c1-100 /home/deploy/.ssh/authorized_keys
```

**6. Mac: comprobar que tu llave no sirve para `deploy`.** Tiene que responder `Permission denied (publickey)`.

```bash
ssh -F /dev/null -o IdentitiesOnly=yes -i ~/.ssh/id_ed25519_contabo deploy@"$(ssh -G contabo | awk '/^hostname /{print $2}')" true
```

La prueba de que la llave de GitHub funciona es el siguiente merge a `develop`: el job «desplegar en staging» tiene que
terminar en verde.

**Producción, cuando exista `/srv/pintech/prod`:** los pasos 4 y 5 con `production` como entorno de GitHub y `prod` en
el `command=`, otra llave (`-C pintech-deploy-production`) y `sudo tee -a` para **agregar** la línea en vez de reemplazar
el archivo.

**Cómo se deshace** (en el servidor; no afecta a los contenedores):

```bash
sudo rm /etc/sudoers.d/pintech-deploy
sudo userdel -r deploy
sudo sed -i 's/^AllowUsers andres deploy$/AllowUsers andres/' /etc/ssh/sshd_config.d/00-pintech.conf
sudo sshd -t && sudo systemctl restart ssh
```

Y en GitHub se borran los secretos: `gh secret delete DEPLOY_SSH_KEY --env staging --repo <repo>` (igual con los otros
dos).
