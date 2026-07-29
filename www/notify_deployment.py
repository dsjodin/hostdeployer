#!/usr/bin/env python

import socket
import sys
import argparse

def log(message):
    with open("/tmp/deployment.log", "a") as f:
        f.write(message + "\n")

def send_notification(mac, server_ip):
    try:
        log(f"Connecting to {server_ip}:80")
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.connect((server_ip, 80))
        
        request = f"GET /admin/deployment_complete.php?mac={mac} HTTP/1.0\r\n"
        request += f"Host: {server_ip}\r\n"
        request += "\r\n"
        
        log("Sending HTTP request")
        s.sendall(request.encode())
        
        log("Receiving response")
        response = b""
        while True:
            data = s.recv(4096)
            if not data:
                break
            response += data
        
        s.close()
        
        with open("/tmp/deployment_complete.log", "wb") as f:
            f.write(response)
        
        log("Successfully received response")
        return True
    except Exception as e:
        log(f"Error: {str(e)}")
        return False

def main():
    parser = argparse.ArgumentParser(description='Notify deployment server of completion')
    parser.add_argument('--mac', required=True, help='MAC address of the host')
    parser.add_argument('--server', required=True, help='Server IP address')
    args = parser.parse_args()
    
    log(f"Starting deployment notification for MAC: {args.mac} to server: {args.server}")
    
    success = send_notification(args.mac, args.server)
    if success:
        print("Notification sent successfully")
    else:
        print("Notification failed")
    
    sys.exit(0 if success else 1)

if __name__ == "__main__":
    main()