FROM jrottenberg/ffmpeg:6.1-alpine

RUN apk add --no-cache bash bc curl jq ca-certificates font-dejavu \
    && fc-cache -f

WORKDIR /worker
COPY worker.sh render.sh ./
RUN chmod +x worker.sh render.sh

CMD ["./worker.sh"]
