Plugin WP XWiki ADM
===================

Ce plugin permet de synchroniser tous les champs d'une classe XWiki vers un custom type Wordpress.
La synchronisation se fait dans un seul sens : de XWiki vers Wordpress.


Installation
------------------

Il suffit de déposer le dossier dans le repertoire ```wp-content/plugins``` de l'installation WordPress.


Limitations
------------------

* La première synchronisation peut dépasser le timeout par défault de la plupart des installations PHP (30 secondes).
Pour y remédier, soit relancer la synchro - elle sera reprise là ou elle s'est arrêtée, soit augmenter le timeout dans les settings.
* Il n'y a pas de fichiers de mapping en tre XWiki et Wordpress.
Tous les champs de XWiki sont synchronisés.
Les clés sont simplement préfixées par un underscore dans Wordpress.


TODO
------------------

* Vérifier que l'addresse du XWiki est valide dans les paramètres
