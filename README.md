##Toknot 3.0-devel
**master is develop**

####About
ToKnot is php develop framework

####License
The PHP framework is under New BSD License (http://toknot.com/LICENSE.txt)

The demos is under GNU GPL version 3 or later <http://opensource.org/licenses/gpl-3.0.html>

###[3.0 配置文件中文说明](https://github.com/chopins/toknot/doc/框架配置文件说明(针对3.0).md)

####[中文教程](http://toknot.com/category/tutorials/)

####API and Class Reference
see (http://toknot.com/toknot/)

####Directory Structure
    Toknot/             framework sources code
          Config/       default ini file and load config of class [Availabled]
          Db/           Database opreate, [Availabled]
          Boot/         boot app [Availabled]
          Command/      Command line tool [Availabled]
          Renderer/     view layer renderer [Availabled]
          Exception/    Framework Exception class  [Availabled]

          Share/          The share lib is options [Develop]
          Share/Http         Http opreate
          Share/Process/      Process manage
          Share/User/         User Control model that is like unix file access permissions
          Share/Admin/        Admin model
          
          Toknot.php     the main function
     demos/

####Usage and Configure

1. Simply download the framework, extract it to the folder you would like to keep it in, then create application

2. In command line, use `php -f /yourpath/Toknot/Toknot.php CreateApp` create your application default directory structure flow to the guide  

3. edit /your-app-path/Config/config.ini

4. if be created general application, your should change /your-app-path/{APP-NAME}Base.php for your application

5. into /your-app-path/Controller change Index.php or create other controller file of php

6. use HTML template, create template file under `/your-app-path/View`

7. change `/your-app-path/config/config.ini`

8. if your PHP verision higher than 5.4.0, In command line execute below code:
    $ cd /your-app-path/WebRoot
    $ php -S localhost:8000 index.php -t static/

