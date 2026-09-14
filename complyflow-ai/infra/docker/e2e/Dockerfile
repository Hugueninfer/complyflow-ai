FROM mcr.microsoft.com/playwright:v1.58.2-noble
WORKDIR /workspace/e2e
COPY e2e/package.json e2e/package-lock.json ./
RUN npm ci
