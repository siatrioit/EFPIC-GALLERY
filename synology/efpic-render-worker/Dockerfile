FROM jrottenberg/ffmpeg:6.1-alpine

RUN apk add --no-cache bash bc curl jq ca-certificates font-dejavu \
    && fc-cache -f

WORKDIR /worker
COPY worker.sh render.sh ./
RUN sed -i 's/\r$//' worker.sh render.sh \
    && chmod +x worker.sh render.sh

# jrottenberg/ffmpeg sets ENTRYPOINT to ffmpeg; override so worker.sh runs as bash.
ENTRYPOINT ["/bin/bash", "./worker.sh"]
