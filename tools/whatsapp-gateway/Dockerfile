# The WhatsApp gateway, as a service rather than something somebody remembers to start.
#
# Kept out of the PHP image on purpose: it is a long-lived stateful process holding a
# linked-device session, and it must survive an application deploy untouched. Rebuilding
# the app image should never log the school out of WhatsApp.
FROM node:20-alpine

WORKDIR /gateway

# Dependencies first, so an edit to gateway.mjs does not reinstall Baileys.
COPY package.json ./
RUN npm install --omit=dev --no-audit --no-fund

COPY gateway.mjs ./

# The linked-device credentials. Mounted as a named volume in compose — if this is ever
# left as image state, every deploy logs the school out and somebody has to physically
# scan a QR code again.
ENV AUTH_DIR=/gateway/auth

# Inside a container 127.0.0.1 is the container's own loopback, which the PHP container
# cannot reach. The service is `expose`d and not published, so this stays on the compose
# network. gateway.mjs refuses to start on a non-loopback bind with the default dev key.
ENV BIND=0.0.0.0
ENV PORT=8080

EXPOSE 8080

CMD ["node", "gateway.mjs"]
