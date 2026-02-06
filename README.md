# Wep

# TODO list
- Executar consultar DB
    [ ] Adicionar config de db no config do app
    [ ] Quando tiver erro, disparar um exception para evitar ter que lidar com isso nos Services
        Ex: if ( $getProducts->error ) throw new Exception($getProducts->message);