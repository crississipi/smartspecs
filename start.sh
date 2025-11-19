#!/bin/bash

# Startup script for Replit deployment
# This script starts both PHP and Python services simultaneously

echo "========================================="
echo "Starting AI Chatbot Services..."
echo "========================================="
echo ""

# Get Replit environment variables
REPL_SLUG="${REPL_SLUG:-}"
REPL_OWNER="${REPL_OWNER:-}"
REPL_ID="${REPL_ID:-}"

# Determine base URL
if [ -n "$REPL_SLUG" ] && [ -n "$REPL_OWNER" ]; then
    BASE_URL="https://${REPL_SLUG}.${REPL_OWNER}.repl.co"
    PHP_URL="${BASE_URL}"
    # Python service runs on localhost since both services are on the same machine
    PYTHON_URL="http://localhost:5000"
    PYTHON_PORT="${PORT:-5000}"
else
    # Fallback to localhost for local development
    BASE_URL="http://localhost"
    PHP_URL="${BASE_URL}"
    PYTHON_URL="http://localhost:5000"
    PYTHON_PORT="5000"
fi

echo "Base URL: ${BASE_URL}"
echo "PHP Service (Public): ${PHP_URL}"
echo "Python Service (Internal): ${PYTHON_URL}"
echo ""

# Set environment variables for services
# PYTHON_SERVICE_URL should always be localhost since services run on same machine
export PYTHON_SERVICE_URL="${PYTHON_URL}"
export FRONTEND_URL="${PHP_URL}"

# Check if we're in Replit (has PORT environment variable for web service)
if [ -n "$PORT" ]; then
    # Replit mode: Use PORT for web service (PHP), separate port for Python
    PHP_PORT="${PORT}"
    PYTHON_PORT="${PYTHON_PORT:-5000}"
    
    echo "Replit Mode Detected"
    echo "PHP will run on port: ${PHP_PORT}"
    echo "Python will run on port: ${PYTHON_PORT}"
    echo ""
    
    # Start PHP built-in server in background
    echo "Starting PHP service on port ${PHP_PORT}..."
    php -S 0.0.0.0:${PHP_PORT} -t . > php-server.log 2>&1 &
    PHP_PID=$!
    echo "PHP service started (PID: ${PHP_PID})"
    echo ""
    
    # Start Python service in background
    echo "Starting Python service on port ${PYTHON_PORT}..."
    cd ai_service
    export PORT=${PYTHON_PORT}
    python3 app.py > ../python-server.log 2>&1 &
    PYTHON_PID=$!
    cd ..
    echo "Python service started (PID: ${PYTHON_PID})"
    echo ""
    
    # Wait for both services to be ready
    echo "Waiting for services to start..."
    sleep 5
    
    # Function to cleanup on exit
    cleanup() {
        echo ""
        echo "Stopping services..."
        kill $PHP_PID 2>/dev/null
        kill $PYTHON_PID 2>/dev/null
        echo "Services stopped."
        exit 0
    }
    
    # Trap SIGTERM and SIGINT
    trap cleanup SIGTERM SIGINT
    
    echo "========================================="
    echo "Services are running!"
    echo "PHP: http://0.0.0.0:${PHP_PORT}"
    echo "Python: http://0.0.0.0:${PYTHON_PORT}"
    echo ""
    echo "Logs:"
    echo "  PHP: php-server.log"
    echo "  Python: python-server.log"
    echo ""
    echo "Press Ctrl+C to stop all services"
    echo "========================================="
    echo ""
    
    # Monitor both processes
    while kill -0 $PHP_PID 2>/dev/null && kill -0 $PYTHON_PID 2>/dev/null; do
        sleep 1
    done
    
    # If we get here, one of the services died
    echo "ERROR: One of the services has stopped!"
    cleanup
else
    # Local development mode
    echo "Local Development Mode"
    echo "Starting Python service on port 5000..."
    cd ai_service
    export PORT=5000
    python3 app.py &
    PYTHON_PID=$!
    cd ..
    
    echo ""
    echo "Python service started (PID: ${PYTHON_PID})"
    echo "Start PHP manually with: php -S localhost:8000"
    echo ""
    
    # Wait for Python service
    wait $PYTHON_PID
fi

