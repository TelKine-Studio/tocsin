# Politique de confidentialité — application Tocsin

*Dernière mise à jour : 15 novembre 2026 (serveur TelKine, appareil photo)*

L'application mobile **Tocsin** (iOS et Android) est éditée par **TelKine**. Elle permet de recevoir les notifications publiées sur un ou plusieurs serveurs Tocsin choisis par l'utilisateur.

## Ce que l'application collecte

L'application ne contient aucun outil d'analyse, de mesure d'audience, de publicité ni de suivi. Elle ne communique qu'avec les serveurs Tocsin que vous ajoutez, et avec les services de notification d'Apple ou de Google.

Elle propose par défaut **push.telkine.net**, le serveur Tocsin exploité par TelKine. Si vous l'utilisez, TelKine est l'exploitant de ce serveur au sens de la section suivante. Si vous utilisez un autre serveur, TelKine ne reçoit aucune donnée vous concernant.

## Données envoyées au serveur que vous choisissez

Quand vous ajoutez un serveur Tocsin, l'application lui transmet :

- le **jeton de notification push** de l'appareil (fourni par Apple ou Google), sa plateforme et son environnement (développement ou production) ;
- le **nom de l'appareil** (par exemple « iPhone de Awa »), pour que vous puissiez reconnaître vos appareils et, pour les topics publics, indiquer qui a acquitté une alerte ;
- la **liste des topics** auxquels vous êtes abonné ;
- si le topic est privé, vos **identifiants** au moment de la connexion. L'application conserve ensuite un jeton d'accès révocable dans le trousseau (iOS) ou le Keystore (Android), jamais votre mot de passe.

Le serveur conserve aussi les messages publiés dans les topics auxquels vous êtes abonné, le temps fixé par son exploitant, ainsi que la date et l'auteur des acquittements.

Ces données sont traitées par l'exploitant du serveur, uniquement pour vous délivrer les notifications. Sur un serveur auto-hébergé, il s'agit de vous ou de votre organisation, selon vos propres règles, et TelKine n'y a pas accès.

## Serveur TelKine (push.telkine.net)

Sur push.telkine.net, TelKine traite les données ci-dessus, ainsi que, si vous créez un compte, votre nom, votre adresse e-mail et, pour un plan payant, l'historique de vos paiements (le paiement lui-même est traité par CinetPay ou Stripe : TelKine ne voit jamais vos coordonnées bancaires). Ces données servent uniquement à faire fonctionner le service, à appliquer les quotas de votre plan et à vous contacter à son sujet. Elles ne sont ni vendues, ni partagées, ni utilisées pour de la publicité. Elles sont supprimées quand vous supprimez votre compte ou retirez le serveur de l'application ; vous pouvez en demander l'effacement à tout moment.

## Relais de notifications TelKine

Les serveurs auto-hébergés peuvent faire passer leurs notifications par le relais TelKine, qui les transmet à Apple (APNs) ou Google (Firebase Cloud Messaging). Le relais reçoit le jeton push et la notification le temps de la transmettre. Il ne conserve que des compteurs quotidiens par serveur et **ne stocke jamais le contenu des messages**. En **mode confidentiel**, la notification ne contient qu'un identifiant : l'application télécharge le contenu directement depuis votre serveur, si bien que ni le relais, ni Apple, ni Google ne le voient.

## Appareil photo

L'application utilise l'appareil photo uniquement quand vous choisissez « Scanner un QR code », pour lire le code affiché par un serveur Tocsin. L'image est analysée sur votre téléphone et n'est ni enregistrée ni envoyée. Seul le lien contenu dans le QR code est utilisé, pour ajouter le topic et, s'il le prévoit, connecter l'application à votre compte.

## Données conservées sur l'appareil

L'historique des messages, la liste des serveurs et vos préférences sont stockés localement sur votre appareil. Ils sont supprimés lorsque vous désinstallez l'application.

## Vos droits

Pour supprimer les données détenues par un serveur Tocsin (appareil enregistré, compte), retirez le serveur dans l'application ou contactez son administrateur. Pour push.telkine.net, vous pouvez aussi écrire à TelKine via l'adresse ci-dessous. Pour toute question sur l'application ou le relais : ouvrez une issue sur https://github.com/TelKine-Studio/tocsin/issues.

## Enfants

L'application ne s'adresse pas spécifiquement aux enfants et ne collecte sciemment aucune donnée les concernant.

## Modifications

Toute modification de cette politique sera publiée sur cette page, avec sa date de mise à jour.
