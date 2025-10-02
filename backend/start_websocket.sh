#!/bin/bash

SCRIPT_DIR="/var/www/sbk_19_ru_usr/data/www/securewave.sbk-19.ru/backend"
WEBSOCKET_DIR="$SCRIPT_DIR/websocket"
PID_FILE="$WEBSOCKET_DIR/server.pid"
LOG_FILE="$WEBSOCKET_DIR/server.log"

start_server() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p $PID > /dev/null 2>&1; then
            echo "WebSocket server already running with PID $PID"
            return 1
        fi
    fi
    
    echo "Starting WebSocket server..."
    nohup php "$WEBSOCKET_DIR/server.php" > "$LOG_FILE" 2>&1 &
    PID=$!
    echo $PID > "$PID_FILE"
    echo "WebSocket server started with PID $PID"
    echo "Logs: $LOG_FILE"
}

stop_server() {
    if [ ! -f "$PID_FILE" ]; then
        echo "PID file not found. Server not running?"
        return 1
    fi
    
    PID=$(cat "$PID_FILE")
    
    if ps -p $PID > /dev/null 2>&1; then
        echo "Stopping WebSocket server (PID: $PID)..."
        kill $PID
        rm "$PID_FILE"
        echo "WebSocket server stopped"
    else
        echo "Process with PID $PID not found"
        rm "$PID_FILE"  
    fi
}

restart_server() {
    stop_server
    sleep 2
    start_server
}

status_server() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p $PID > /dev/null 2>&1; then
            echo "WebSocket server is running (PID: $PID)"
            echo "Last log entries:"
            tail -n 10 "$LOG_FILE"
        else
            echo "WebSocket server not running (PID file exists but process not found)"
        fi
    else
        echo "WebSocket server is not running"
    fi
}

case "$1" in
    start)
        start_server
        ;;
    stop)
        stop_server
        ;;
    restart)
        restart_server
        ;;
    status)
        status_server
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status}"
        exit 1
        ;;
esac

exit 0
