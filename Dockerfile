# Dockerfile for Space Cargo Stowage Backend API

# 1. Start from the required Ubuntu base image (Requirement #3)
FROM ubuntu:22.04

# 2. Set environment variables to avoid interactive prompts during installation
ENV DEBIAN_FRONTEND=noninteractive

# 3. Install PHP and the SQLite3 extension
RUN apt-get update && \
    apt-get install -y php php-sqlite3 && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# 4. Set the working directory inside the container
WORKDIR /app

# 5. Copy your backend code into the container's working directory
COPY backend/ /app/

# 6. Expose port 8000 (Requirement #2)
EXPOSE 8000

# 7. Define the command to run when the container starts
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app", "/app/index.php"]