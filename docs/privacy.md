# Politique de confidentialité — application Tocsin

*Dernière mise à jour : 15 novembre 2026*

L'application mobile **Tocsin** (iOS et Android) est éditée par **TelKine**. Elle permet de recevoir les notifications publiées sur un ou plusieurs serveurs Tocsin choisis par l'utilisateur.

## Ce que TelKine collecte

**Rien.** L'application ne contient aucun outil d'analyse, de mesure d'audience, de publicité ni de suivi. TelKine ne reçoit aucune donnée personnelle depuis l'application et ne crée pas de compte pour ses utilisateurs.

## Données envoyées au serveur que vous choisissez

Quand vous ajoutez un serveur Tocsin, l'application communique uniquement avec ce serveur, exploité par vous ou par votre organisation :

- le **jeton de notification push** de l'appareil (fourni par Apple ou Google), sa plateforme et son environnement (développement ou production) ;
- le **nom de l'appareil** (par exemple « iPhone de Awa »), pour que vous puissiez reconnaître vos appareils et, pour les topics publics, indiquer qui a acquitté une alerte ;
- la **liste des topics** auxquels vous êtes abonné ;
- si le topic est privé, vos **identifiants** au moment de la connexion. L'application conserve ensuite un jeton d'accès révocable dans le trousseau (iOS) ou le Keystore (Android), jamais votre mot de passe.

Ces données sont traitées par l'exploitant de ce serveur, selon ses propres règles. TelKine n'y a pas accès.

## Relais de notifications TelKine

Les serveurs auto-hébergés peuvent faire passer leurs notifications par le relais TelKine, qui les transmet à Apple (APNs) ou Google (Firebase Cloud Messaging). Le relais reçoit le jeton push et la notification le temps de la transmettre. Il ne conserve que des compteurs quotidiens par serveur et **ne stocke jamais le contenu des messages**. En **mode confidentiel**, la notification ne contient qu'un identifiant : l'application télécharge le contenu directement depuis votre serveur, si bien que ni le relais, ni Apple, ni Google ne le voient.

## Données conservées sur l'appareil

L'historique des messages, la liste des serveurs et vos préférences sont stockés localement sur votre appareil. Ils sont supprimés lorsque vous désinstallez l'application.

## Vos droits

Pour supprimer les données détenues par un serveur Tocsin (appareil enregistré, compte), retirez le serveur dans l'application ou contactez son administrateur. Pour toute question sur l'application ou le relais : ouvrez une issue sur https://github.com/TelKine-Studio/tocsin/issues.

## Enfants

L'application ne s'adresse pas spécifiquement aux enfants et ne collecte sciemment aucune donnée les concernant.

## Modifications

Toute modification de cette politique sera publiée sur cette page, avec sa date de mise à jour.
