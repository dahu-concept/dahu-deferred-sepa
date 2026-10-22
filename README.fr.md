# Dahu Deferred SEPA

Prélève le mandat SEPA Stripe enregistré d'un client WooCommerce un nombre de
jours défini après l'expédition de sa commande, au lieu de le prélever à la
commande.

Stripe prélève un SEPA dès que la commande est passée. Certains clients ne
doivent pas être prélevés à ce moment-là — un compte professionnel à 30 jours,
un grossiste — et cette extension est l'option de paiement qui attend.

*La version anglaise de ce document est dans `README.md`. En cas d'écart, c'est
elle qui fait foi : c'est elle qui part sur wordpress.org.*

## Comment ça marche

1. **À la commande.** La passerelle « Prélèvement SEPA différé » ne prend aucun
   argent. Elle retrouve le mandat que le client a déjà enregistré chez Stripe,
   écrit le client et le moyen de paiement sur la commande, et met la commande
   **en attente** — à préparer et à expédier, mais pas payée, parce que dire
   autre chose serait un mensonge dans chaque rapport que la boutique consulte.
2. **Au passage en Terminée.** Une action planifiée unique est mise en file, à N
   jours. Huit par défaut ; redéfinissable globalement et par client.
3. **Au déclenchement.** Un PaymentIntent hors session est créé et confirmé
   contre le mandat enregistré, porteur d'une clé d'idempotence persistante.
4. **Quelques jours plus tard.** Le SEPA est encaissé. Un webhook dédié entend
   le résultat et termine ou fait échouer la commande.

## L'approbation demande les deux côtés

Un mandat Stripe est juridiquement valable dès que le client l'a signé. Que la
boutique accepte d'être payée **en retard** par ce client est une décision
commerciale distincte, et cette extension tient les deux séparées.

- Un administrateur désigne l'un des IBAN enregistrés du client comme **mandat
  actif**, sur le profil utilisateur du client.
- Le client a ce même IBAN comme **son propre** moyen de paiement par défaut.

Ni l'un ni l'autre ne suffit seul. C'est ce qui empêche un client de basculer
discrètement vers un IBAN que personne n'a approuvé, et c'est la raison pour
laquelle la passerelle est invisible pour la quasi-totalité des acheteurs.

## Fonctionnalités

| | |
|---|---|
| **Prélevé une fois** | Une clé d'idempotence persistante par commande. Une action rejouée, un dépassement de délai ou une nouvelle tentative ne peuvent pas produire un second débit. |
| **Date déplaçable** | Affichée sur l'écran de commande et déplaçable jusqu'au déclenchement. Chaque déplacement est compté et conservé. |
| **Prélever maintenant** | Derrière un contrôle de capacité, un nonce et une confirmation. |
| **Webhook propre** | Point d'accès distinct, secret de signature distinct, n'agit que sur les prélèvements de cette extension. |
| **Conditions par client** | Un délai posé sur le profil d'un client prime sur le délai global. |
| **Écran des prélèvements** | L'encaissement attendu, et un historique filtrable, triable, exportable. |
| **Rien n'échoue en silence** | Un prélèvement refusé part en courriel vers la boutique, une fois. Un prélèvement dont la date est passée et qui n'a jamais tourné est listé en haut de l'écran et signalé par une vérification quotidienne — le prélèvement différé est le seul chemin de paiement sans client devant l'écran pour voir l'échec. |
| **Un prélèvement qui revient** | Un prélèvement que Stripe a accepté puis refusé avant encaissement ne prend aucun argent : la commande est donc **réouverte** — la garde « déjà prélevé » de l'extension laissait jusqu'ici une telle commande sans aucune issue : ni prélèvement, ni replanification, ni « réglé autrement ». Un échec ou une contestation sur un prélèvement déjà encaissé est laissé exactement en l'état, parce que défaire un encaissement sur un événement ambigu est le seul geste qui pourrait coûter de l'argent — et il part en courriel, ce qui n'était pas le cas avant. |
| **Diagnostics** | L'extension explique sa propre disponibilité au lieu de vous laisser deviner. |
| **HPOS** | Les deux stockages de commandes. Aucune table propre. |

## Prérequis

- WordPress 6.0+, PHP 7.4+
- WooCommerce 7.0+
- Le prélèvement SEPA fonctionnel chez Stripe — en pratique l'extension
  officielle
  [WooCommerce Stripe Payment Gateway](https://wordpress.org/plugins/woocommerce-gateway-stripe/),
  qui est la façon dont un client signe et enregistre un mandat.

La clé secrète Stripe vient soit du réglage propre à cette extension — une clé
restreinte n'autorisant que l'écriture de PaymentIntents suffit — soit de la
configuration de l'extension officielle. Renseigner votre propre clé évite que
cette extension dépende des réglages enregistrés par une autre.

## Mise en place

Activez la passerelle sous **WooCommerce → Réglages → Paiements → Prélèvement
SEPA différé**, puis créez le point d'accès du webhook dans votre tableau de
bord Stripe :

```
https://votresite.example/wp-json/dahu-deferred-sepa/v1/webhook
```

à l'écoute de `payment_intent.succeeded`, `payment_intent.payment_failed` et
`charge.dispute.created`. Mettez son secret de signature dans `wp-config.php` :

```php
define( 'DAHU_DEFERRED_SEPA_WEBHOOK_SECRET', 'whsec_...' );
```

Jamais dans un fichier d'extension. Tant que la constante est vide, le webhook
n'accepte rien, ce qui est le défaut sûr : une notification forgée ne doit
jamais pouvoir marquer une commande comme payée.

Le mode test et le mode production sont **deux points d'accès avec deux
secrets différents**.

## Avant de l'activer

Un mandat SEPA porte des obligations envers le payeur, et elles sont les vôtres,
pas celles de cette extension. Selon le mandat employé, vous pouvez devoir au
client une notification préalable de la date et du montant de chaque débit — et
différer un prélèvement change cette date. Vérifiez ce qu'exigent votre mandat
et votre banque, et dites à vos clients à quoi s'attendre.

Stripe n'accepte le prélèvement SEPA qu'**en euros**. Une commande dans une
autre devise est refusée avec une note de commande, plutôt que prélevée dans la
mauvaise unité.

## Points d'extension

### Filtres

| Filtre | Rôle |
|---|---|
| `dahu_deferred_sepa_delay_days` | Le délai de prélèvement résolu, par client. |
| `dahu_deferred_sepa_excluded_roles` | Rôles à qui la passerelle n'est jamais proposée. Vide par défaut. |
| `dahu_deferred_sepa_secret_key` | La clé secrète Stripe, pour une boutique qui garde ses clés hors de la base. |
| `dahu_deferred_sepa_payment_intent_body` | Le corps du PaymentIntent avant envoi. |
| `dahu_deferred_sepa_charge_hour` | Heure de la journée à laquelle part un prélèvement replanifié. 9 par défaut. |
| `dahu_deferred_sepa_settlement_days` | Délai d'encaissement SEPA supposé, en jours ouvrés. 3 par défaut. |
| `dahu_deferred_sepa_webhook_tolerance` | Ancienneté maximale d'un webhook. 5 minutes par défaut. |
| `dahu_deferred_sepa_csv_delimiter` | Séparateur CSV de l'export. Virgule par défaut. |
| `dahu_deferred_sepa_mandate_submitted_admin_email` | Le courriel « mandat soumis » de la boutique. |
| `dahu_deferred_sepa_mandate_submitted_customer_email` | Le courriel « nous avons reçu votre IBAN » du client. |
| `dahu_deferred_sepa_mandate_approved_email` | Le courriel « approuvé » du client. |
| `dahu_deferred_sepa_overdue_grace` | Le retard toléré avant de signaler un prélèvement. Un jour par défaut ; jamais zéro. |
| `dahu_deferred_sepa_charge_refused_email` | L'alerte « prélèvement refusé » de la boutique. |
| `dahu_deferred_sepa_overdue_email` | L'alerte quotidienne « prélèvements jamais exécutés ». |

### Constantes

Toutes redéfinissables depuis `wp-config.php`.

| Constante | Défaut | Rôle |
|---|---|---|
| `DAHU_DEFERRED_SEPA_WEBHOOK_SECRET` | *(vide)* | Secret de signature du webhook. À définir dans `wp-config.php`. |
| `DAHU_DEFERRED_SEPA_DELAY_DAYS` | `8` | Délai de dernier recours, employé quand ni le réglage du client ni celui de la passerelle n'est posé. |
| `DAHU_DEFERRED_SEPA_DEBUG` | `false` | Force l'affichage des diagnostics, pour un site dont l'écran de réglages est inaccessible. |

## Sans affiliation

Cette extension n'est ni affiliée à Stripe, ni approuvée ou sponsorisée par
Stripe. Les noms « Stripe » et « SEPA » ne servent qu'à dire avec quoi elle
fonctionne.

## Licence

MIT. Voir [LICENSE](LICENSE).
