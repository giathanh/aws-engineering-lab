FROM otel/opentelemetry-collector-contrib:0.120.0
COPY docker/otel.yaml /etc/otelcol-contrib/config.yaml
