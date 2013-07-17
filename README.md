PHP-WS-Server
=============

PHP WebSocket Server

This is simple WebSocket Server project. 

## Simple Socket Example

You can check simple socket examples (not WebSocket) by server.php (Server) and client.php (Client). Server will start and listen for new connections. Client will connect and send message to the server. In the moment client cannot receive messages from the server (this is what I need for now).

## Simple WebSocket Example

You can check simple websocket example by ws_server.php (Server) and ws_client.html (Client). Server will start and listen for new connections. All connections from the same IP as the server IP will be considured as an internal comunication between server and php script executed by apache for example. This comunication is not by WebSocket standarts. The purpose of this comunication is to inform server to send updates to all the clients (websocket clients). If someone request POST/GET (by ajax or by other method) php script will be executed on the server. At the end of the script you can put the content of the client.php. In this case your php script executed by apache (or other server) will inform the websocket server to update the informacion on all connected clients.

NOTE: Parts of the WebSocket standart are not implemented. Clients (client.php/BtzSocket) can not receive messages.





