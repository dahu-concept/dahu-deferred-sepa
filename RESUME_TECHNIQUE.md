# Dahu Deferred SEPA — résumé technique

Document interne. Le `README.md` et le `readme.txt` sont publics ; celui-ci est
la mémoire du portage.

---

## 1. Portage depuis la version Annad

| | |
|---|---|
| Dépôt source | `/Dev/dahu-sepa-differe` |
| Fichier source | `dahu-sepa-differe-stripe.php` — **un seul fichier de 2 805 lignes, 112 Ko** |
| Version de référence | 1.8.0 |
| Date du portage | 8 octobre 2026 |
| Version publique | 1.0.0 |
| Licence | GPL v2 côté Annad → **MIT** côté public (Hugo est seul auteur) |

La version Annad était déjà largement préparée pour une diffusion : `README.md`
et `README.txt` en anglais, en-têtes propres, et deux entrées de changelog
(1.1.0 et 1.7.2) qui retirent explicitement des chaînes et des règles propres à
la boutique. Le portage a donc surtout consisté à renommer, découper, traduire
et retirer du code mort.

### 1.1 Renommage

418 remplacements, appliqués **au tokenizer PHP** (identifiants et littéraux
traités séparément), puis vérifiés par comparaison de squelettes de jetons : la
structure du fichier renommé est identique à l'originale sous la carte de
renommage, 11 479 jetons.

| Annad | Public |
|---|---|
| `annad_sepa_*` (fonctions) | `dahu_deferred_sepa_*` |
| `ANNAD_SEPA_*` (constantes) | `DAHU_DEFERRED_SEPA_*` |
| `Annad_SEPA_Deferred_Gateway` | `Dahu_Deferred_SEPA_Gateway` |
| `Annad_Sepa_History_Table` | `Dahu_Deferred_SEPA_History_Table` |
| `_annad_sepa_*` (métas) | `_dahu_deferred_sepa_*` |
| `annad_sepa_deferred` (id passerelle) | `dahu_deferred_sepa` |
| `annad-sepa/v1` (REST) | `dahu-deferred-sepa/v1` |
| `annad-sepa` (groupe Action Scheduler) | `dahu-deferred-sepa` |
| `annad-sepa-prelevements` (slug d'écran) | `dahu-deferred-sepa-charges` |
| `annad_from` / `annad_to` / `annad_result` (paramètres GET) | `dahu_from` / `dahu_to` / `dahu_result` |
| `dahu-sepa-differe-stripe` (text domain) | `dahu-deferred-sepa` |
| `{delai}` (jeton de description) | `{days}` |

**Les métas changent de nom, donc les deux versions ne partagent aucune
donnée.** C'est voulu : elles peuvent cohabiter sur un même site, et la version
publique ne lira jamais l'historique de prélèvements d'Annad.

#### Deux pièges du renommage, pour mémoire

1. **Les noms de constantes et de classes apparaissent aussi comme littéraux de
   chaîne** — dans `define()`, `defined()`, `class_exists()` et la liste des
   passerelles. Les omettre définit les constantes sous l'ancien nom alors que
   le code les lit sous le nouveau : fatale au chargement, et `php -l` ne voit
   rien. Trouvé par un compteur de résidus, pas par le contrôle de syntaxe.
2. **`annad_from` / `annad_to` / `annad_result` forment une paire** : lus dans
   `$_REQUEST` d'un côté, écrits dans le formulaire et l'URL de pagination de
   l'autre. Renommer un seul côté casse les filtres en silence.

### 1.2 Découpage

Un fichier de 2 805 lignes, dont les sections étaient numérotées
`4bis`, `4ter`, `4quater`, `4quinquies`, `4sexies`, `4sexies-bis`, `4septies`,
`4octies`, `4novies` — et dans le désordre. Découpé en 117 unités de premier
niveau au tokenizer, réparties en 10 fichiers.

| Fichier | Rôle |
|---|---|
| `dahu-deferred-sepa.php` | en-tête, constantes, lecture des réglages, amorçage |
| `includes/stripe.php` | appels API, clé secrète, idempotence, formats, journal |
| `includes/schedule.php` | planification, exécution, résultat, annulation |
| `includes/gateway.php` | la passerelle de paiement et son autorisation |
| `includes/mandates.php` | mandat actif, détection de soumission, e-mails, Mon compte |
| `includes/order-screen.php` | metabox : date due, déplacement, prélèvement immédiat |
| `includes/checkout.php` | masquage du SEPA immédiat de Stripe |
| `includes/webhook.php` | endpoint REST et vérification de signature |
| `includes/diagnostics.php` | panneaux « pourquoi la passerelle ne s'affiche pas » |
| `includes/admin-charges.php` | écran À venir / Historique, export CSV |

**Preuve du découpage** : le multiensemble des squelettes de jetons par unité
des 10 fichiers est égal à celui du fichier source privé des unités
volontairement retirées. 113 unités conservées, 11 retirées, 0 ajoutée, 0
dupliquée, 0 modifiée.

### 1.3 Ce qui a été retiré

**Trois mécanismes abandonnés**, conservés côté Annad comme code mort derrière
des constantes jamais définies, avec des commentaires datés de juillet 2026 :

| Constante de garde | Ce qu'elle gardait | Pourquoi c'est retiré |
|---|---|---|
| `ANNAD_SEPA_DEFER_AT_CHECKOUT` (définie à `false`) | 3 `add_filter` sur des noms de filtres **devinés** du plugin Stripe, plus `defer_checkout()`, `defer_checkout_upe()` et la sonde `probe()` | Le commentaire du code l'admet : « ⚠️ Les noms de filtres ci-dessous dépendent de la version du plugin Stripe ». Une extension publique ne branche pas des filtres dont elle ne sait pas s'ils existent. |
| `ANNAD_SEPA_DEDUPE_ON_ADD_HOOKS` (jamais définie) | `enforce_single_mandate()`, `handle_add_payment_method_success()` | Le commentaire Annad : coexister avec l'autre mécanisme « créait une suppression en double, **vidant complètement la liste des mandats** ». Un chemin de code capable d'effacer les moyens de paiement d'un client ne part pas en public. |
| `ANNAD_SEPA_AUTO_DEDUPE_TOKENS` (jamais définie) | `dedupe_on_account_page()` | Abandonné au profit du choix explicite du mandat actif. Même risque. |

Conséquence : `notify_mandate_replaced()` n'était appelée que depuis ces deux
derniers mécanismes. Devenue inatteignable, retirée aussi — son rôle est couvert
par `notify_iban_submitted()`, qui part sur un changement de défaut.

**La sonde persistée `capture_gateway_filters()`** est retirée également. Elle
écrivait un historique de 5 entrées dans une option **à chaque chargement du
checkout** quand le mode debug était actif. Le diagnostic public garde la même
information en lecture seule (la liste de ce qui est accroché au filtre, avec
fichier et ligne), sans aucune écriture.

**Quatre reliquats de développement** :

- `'🔧 Diagnostic du Dahu 🐐 </br> Promis je le desactiverai ensuite'` — une
  note à soi-même affichée dans l'interface de la fiche commande.
- `<a href="https://github.com/Epilouptique">` dans la ligne méta de l'écran
  Extensions : **le dépôt est privé**, cette URL ne doit jamais partir dans une
  extension.
- Les deux panneaux de sonde de la metabox, qui lisaient des options
  (`dahu_serials…_probe`, `…_token_probe`) écrites par le code retiré ci-dessus :
  ils n'auraient plus jamais rien affiché.
- La « reprise au mieux » de l'historique : côté Annad, les commandes prélevées
  avant l'arrivée des métas de suivi n'ont ni date d'exécution ni résultat, donc
  l'écran les déduisait de la date de paiement et du statut, et marquait la ligne
  « historique partiel ». Une extension neuve n'a pas d'historique antérieur à
  ses propres métas : mécanisme retiré, et avec lui le fait de deviner un
  résultat à partir d'un statut de commande.

### 1.4 Ce qui a été généralisé

| Spécificité Annad | Réglage ou filtre public |
|---|---|
| `ANNAD_SEPA_PARTICULIER_ROLES = array( 'customer' )`, codé en dur | filtre `dahu_deferred_sepa_excluded_roles`, **vide par défaut** |
| `ANNAD_SEPA_NOTIFY_EMAIL` (constante `wp-config`) | réglage **« Notify this address »** de la passerelle, repli sur `admin_email` |
| `ANNAD_SEPA_CONTACT_EMAIL` (idem) | réglage **« Contact address for customers »**, même repli |
| `ANNAD_SEPA_DEBUG` (constante seule) | réglage **« Diagnostics »** ; la constante reste un forçage |
| Masquage du SEPA natif Stripe, toujours actif | réglage **« Hide it at checkout »**, **désactivé par défaut** |
| Clé secrète lue uniquement dans `woocommerce_stripe_settings` | réglages `secret_key` / `test_secret_key` propres à la passerelle **d'abord**, emprunt au plugin officiel ensuite, filtre `dahu_deferred_sepa_secret_key` en dernier recours |
| `Europe/Paris` en dur (7 occurrences) | `wp_timezone()` |
| `'d/m/Y à H\hi'` en dur | `get_option( 'date_format' )` + `get_option( 'time_format' )` |
| 09h00 de déclenchement en dur | filtre `dahu_deferred_sepa_charge_hour`, défaut 9 |
| « 2-3 jours ouvrés » / « ~3 jours » en dur | filtre `dahu_deferred_sepa_settlement_days`, défaut 3 |
| « confirmation sous 72h » promise au client (3 endroits) | retiré : une extension ne prend pas d'engagement de délai au nom de la boutique |
| Séparateur CSV `;` et virgule décimale | virgule standard et point décimal, filtre `dahu_deferred_sepa_csv_delimiter` |
| Tolérance de rejeu de 300 s en dur | filtre `dahu_deferred_sepa_webhook_tolerance` |

### 1.5 Ce qui a été corrigé

Quatre défauts, dont deux sérieux.

1. **L'historique chargeait toutes les commandes de la boutique.**
   `get_history_rows()` appelait `wc_get_orders( 'limit' => -1 )` sur la
   passerelle, instanciait **chaque** commande, filtrait en PHP, triait en PHP,
   puis paginait en PHP — à chaque affichage de l'écran. Ça fonctionne à
   l'échelle d'Annad et tombe sans avertissement ailleurs.

   La première réécriture a poussé les filtres dans une `meta_query` et le tri
   dans `orderby => meta_value_num`. **Le banc a montré que ça ne filtrait
   rien** : l'écran aurait listé toutes les commandes de la passerelle en ayant
   l'air filtré. Voir §4.7 — c'est le point le plus important de ce document.
   La version retenue n'emploie que ce qui fonctionne réellement, et
   l'export CSV est **diffusé page par page** (200 lignes), donc n'a plus à
   tenir en mémoire d'un coup.

2. **Un prélèvement refusé alors qu'il était prélevable.** `do_confirm()` lisait
   `_stripe_customer_id` et `_stripe_source_id` **sur la commande** uniquement.
   Une commande passée via la passerelle SEPA du plugin Stripe officiel — l'un
   des identifiants que l'extension reconnaît pourtant — peut ne porter ni l'un
   ni l'autre, et le prélèvement était alors refusé avec une note de commande,
   alors que `find_saved_mandate()` et `resolve_customer_id()` existent et
   savent répondre. Un repli sur le compte client a été ajouté, qui écrit
   ensuite les deux métas sur la commande.

3. **Aucun contrôle de devise.** `amount_in_cents()` multiplie par 100, ce qui
   est juste pour l'euro et faux partout ailleurs — Stripe n'accepte le SEPA
   qu'en euro de toute façon. Une commande dans une autre devise était envoyée
   telle quelle. Elle est désormais refusée avec une note de commande explicite,
   au moment du prélèvement **et** au checkout.

4. **La réponse Stripe complète finissait dans une note de commande** en cas de
   statut inattendu (`wp_json_encode( $result )`), donc sous les yeux de toute
   personne pouvant lire la commande. La note dit maintenant ce qui s'est passé,
   et la réponse va dans le journal WooCommerce.

Plus un ajout : **un journal**. `dahu_deferred_sepa_log()` écrit dans le journal
WooCommerce sous la source `dahu-deferred-sepa`. Un prélèvement différé part
sans personne devant l'écran ; la note de commande dit l'issue côté boutique, le
journal dit ce que Stripe a répondu.

### 1.6 Interface

Bloc de jetons `--dahu-admin-*` recopié **à l'octet** depuis
`/Dev/dahu-public/UI-ADMIN-DAHU.md`. Toutes les valeurs en dur de la version
Annad (`#1a7a2e`, `#fff3cd`, `#8a1f11`, `#0a4b78`, `padding:20px`,
`max-width:950px`, `font-size:11px`…) et **tous** les attributs `style=`
deviennent des classes `dahu-sepa-*`.

Trois blocs de JavaScript en ligne, dont deux avec du texte français en dur,
passent dans `assets/js/` avec leurs chaînes par `wp_localize_script()` :

| Avant | Après |
|---|---|
| `<script>` dans `badge_active_mandate_js()` + `account_payment_methods_footer_note()` | `assets/js/account.js` |
| `<script>` jQuery dans `hide_native_sepa_in_order_screen()`, sur `admin_footer` | `assets/js/admin-payment-method.js`, sur `admin_enqueue_scripts` |
| `onclick="return confirm( '…' )"` sur le bouton de prélèvement | `assets/js/admin-order.js` |

### 1.7 Internationalisation

Côté Annad : **2 chaînes traduisibles** dans tout le fichier, et **142 littéraux
français accentués** affichés en dur. Côté public : **181 chaînes traduisibles,
0 littéral français**, mesuré au tokenizer avec un détecteur validé sur témoin
positif et témoin négatif.

### 1.8 Conformité wordpress.org

- Le slug ne contient ni `stripe` ni `woocommerce`. Les deux marques
  n'apparaissent que dans la description, pour dire avec quoi l'extension
  fonctionne, et le `README.md` comme le `readme.txt` portent une mention de
  non-affiliation.
- `Requires Plugins: woocommerce` dans l'en-tête.
- Aucun SDK embarqué : trois types d'appels, en `wp_remote_request()`.
- La route REST `permission_callback => __return_true` est le seul cas toléré du
  contrôle de conformité du chantier, parce qu'elle vérifie elle-même une
  signature HMAC en temps constant. **Le contrôle a été affiné** plutôt que
  contourné : il n'accepte `__return_true` que dans un fichier qui appelle
  `hash_equals(`, et refuse toujours une route réellement ouverte (vérifié par
  injection d'une violation).

### 1.9 Évolutions Annad pas encore reportées

- [ ] Rien à ce jour : le portage part de la 1.8.0, dernière version Annad au
      8 octobre 2026.

---

## 2. Banc fonctionnel

`_bench/t-deferred-sepa.php` — **263 tests, 16 sections**, sur un vrai
WordPress 7.1 avec une vraie WooCommerce 11.1 et une vraie base. Idempotent
(vérifié sur trois exécutions consécutives).

La ligne est tirée exactement là où est le réseau : **tout ce qui se trouve en
amont de `wp_remote_request()` est testé pour de vrai**, et le aller-retour
Stripe est la liste à cocher du §3. Un banc qui simulerait la couche HTTP
n'affirmerait que des choses sur sa propre simulation.

| § | Ce qui est couvert |
|---|---|
| 1 | résolution du délai : constante → réglage → méta client → filtre, et le plancher à 0 |
| 2 | rôles exclus, et le fait qu'un rôle exclu n'est jamais autorisé même avec un mandat |
| 3 | **la double condition du mandat** : approuvé sans être le défaut du client, défaut sans être approuvé, mandat d'un autre client, jeton supprimé, jeton qui n'est pas un `pm_` |
| 4 | **signature du webhook** : secret absent, en-tête vide, en-tête malformé, charge signée mal construite, mauvais secret, mauvais corps, tolérance de rejeu |
| 5 | montants (arrondi au centime, pas troncature), refus de devise, aplatissement form-encoded, jours ouvrés, formats de date dans le fuseau du site, validation `Y-m-d` |
| 6 | comptabilité de la planification : première date figée, déplacements comptés, replanification à la même date non comptée, une seule action planifiée, `mark_done()` |
| 7 | **clé d'idempotence** : stable sur deux appels, stable après rechargement, distincte par commande |
| 8 | **chaque branche de `handle_result()`** : `succeeded`, `processing`, erreur Stripe, erreur portant un intent en cours, erreur portant un intent mort, erreur réseau, statut inattendu, et le fait que la réponse brute ne part pas dans une note |
| 9 | annulation : commande annulée, et aucune note parasite quand rien n'était planifié |
| 10 | planification au passage en « Terminé », non-replanification, autre passerelle ignorée |
| 11 | **résolution de la clé secrète** : clé propre, mode test, emprunt au plugin officiel, c'est SON mode test qui décide, option illisible → chaîne vide jamais un repli, filtre |
| 12 | **filtres de l'historique vérifiés par comparaison d'ensembles** (voir §4.7), ordre, pagination des deux branches, et aucune commande jamais prélevée |
| 12bis | **tous les défauts trouvés en revue** (voir §6), chacun prouvé par injection |
| 13 | libellés et pastilles de résultat |
| 14 | aucune alerte PHP imputable à l'extension |

Une particularité du banc, notée dans le fichier : la passerelle du jeton SEPA
de test est `dahu_deferred_sepa` et non `stripe`, parce que
`WC_Payment_Token_Data_Store::get_tokens()` restreint aux identifiants des
passerelles **enregistrées** quand aucune n'est demandée. Le plugin Stripe
officiel n'étant pas installé sur le banc, un jeton enregistré sous `stripe`
serait invisible et toutes les assertions du §3 passeraient pour la mauvaise
raison.

**Cela vaut aussi en production** : si une boutique désactive le plugin Stripe,
ses jetons SEPA cessent d'être listés, `find_saved_mandate()` renvoie `false` et
la passerelle disparaît du checkout. C'est le bon comportement — sans ce plugin
aucun mandat ne peut être signé — mais c'est une dépendance que rien n'écrivait.

---


## 2 bis. Un prélèvement qui n'a jamais eu lieu, écrit le 2026-10-19

Venu d'un incident de production sur la version Annad : huit commandes
« Terminées » n'avaient jamais été prélevées, sur des échéances étalées du
4 septembre au 14 octobre, et personne ne l'a su pendant six semaines. La
cause y était double — une planification qui ne vérifiait pas le mandat, et un
historique qui déduisait « encaissé » de `is_paid()`, vrai pour toute commande
terminée. Cette extension-ci ne portait ni l'une ni l'autre : le mandat est
revérifié au moment du prélèvement avec des refus nommés, et l'historique est
borné sur l'existence d'une tentative. Ce qu'elle partageait, c'est le silence.

Le prélèvement différé est le seul chemin de paiement sans personne devant
l'écran. La commande passe en « Terminée », la marchandise part, et l'argent
est censé bouger des jours plus tard, dans une tâche planifiée, chez Stripe.
Tous les autres chemins ont un client assis devant un écran qui apprend tout de
suite que ça n'a pas marché.

`includes/alerts.php` ne fait donc qu'une chose : la boutique l'apprend.

### Deux façons de ne rien encaisser, et ce ne sont pas les mêmes

1. **Le prélèvement a tourné et a été refusé.** Il y a une raison, écrite sur
   la commande par `dahu_deferred_sepa_refuse()` : pas de mandat approuvé,
   approbation retirée, IBAN changé, Stripe qui dit non. La commande porte un
   résultat et elle est dans l'historique. Ce qui manquait : le dire à la
   boutique. C'est fait, par courriel, une fois.
2. **Il n'a jamais tourné.** L'échéance est passée et rien ne s'est produit —
   file bloquée, site sans trafic pour que le cron l'attrape, action perdue. La
   commande porte une date dépassée, aucun résultat, et **aucun écran ne le
   disait** : elle restait dans « À venir » avec une date d'encaissement qui
   n'arriverait jamais. `dahu_deferred_sepa_is_overdue()` la nomme, l'écran la
   sort de « À venir » et la met dans son propre bloc en rouge, sans estimation
   d'encaissement — une estimation sur un prélèvement jamais parti est un
   mensonge — et un balayage quotidien l'envoie par courriel.

### Une alerte par échec, et pas une par jour

Une alerte qui se répète est une alerte qu'on classe dans un dossier, et une
alerte classée est pire que rien : c'est un silence qu'on croit couvert. Chaque
alerte porte donc une empreinte — ce qui a échoué, la date d'échéance, et la
phrase que lit une personne — gardée sur la commande dans
`_dahu_deferred_sepa_alerted`, plafonnée aux vingt dernières. Le même échec
redétecté demain n'envoie rien ; une replanification change la date, donc
l'empreinte, donc un prélèvement qui échoue de nouveau après une reprise est
redit. C'est le comportement voulu des deux côtés : pas de répétition de la
même nouvelle, et pas d'étouffement d'une nouvelle qui ressemble à une
ancienne.

L'empreinte est écrite **avant** que le courriel soit passé au serveur de
messagerie. Des deux erreurs possibles, l'une envoie un courriel deux fois,
l'autre l'envoie en boucle.

### Un seul courriel pour tout un balayage

Une boutique qui découvre douze commandes impayées a besoin d'une liste
qu'elle lit, et douze courriels ne sont pas une liste qu'on lit dans l'ordre
qui compte. La plus vieille d'abord : celle qui est impayée depuis le plus
longtemps est celle à regarder en premier, et c'est celle qui se retrouve en
bas de n'importe quel autre tri.

### Ce qui ne sort jamais par courriel

La réponse brute du prestataire. Elle reste là où elle est déjà — la note de
commande et le journal — et le courriel porte la phrase écrite pour une
personne, plus le numéro de commande. Un courriel passe par le serveur de
quelqu'un d'autre, se fait transférer, et reste pour toujours dans le client de
son destinataire. Trois assertions nommées l'exigent : aucun `{`, aucun
`cus_`, aucun `pm_`.

### Le balayage ne prélève rien

Ni retenter, ni replanifier, ni toucher Stripe. Un prélèvement qui a
peut-être été envoyé n'est pas un prélèvement à renvoyer depuis une tâche
planifiée sans personne devant l'écran : tout le travail du balayage est de
mettre une personne devant la question.

### Et la planification ne refuse jamais

Il aurait été facile de vérifier le mandat au passage en « Terminée » et de
refuser de planifier quand il n'y en a pas. C'est la mauvaise réponse, et pas
pour une raison théorique : une boutique qui approuve un mandat **après** avoir
passé la commande en « Terminée » fait une journée de travail normale, et le
délai existe précisément pour que les choses puissent changer entre la commande
et le prélèvement. La version Annad a d'ailleurs dû remplacer son premier
verrou le jour même, avant déploiement, pour cette raison exacte.

Donc le mandat est relu au moment du prélèvement, comme toujours — et l'état
tel qu'il est **maintenant** est dit dans la note de planification, pour que la
boutique corrige dans les jours qu'elle a au lieu de l'apprendre le jour dit.

### Délai de grâce

Un jour, et ce n'est pas une heure : Action Scheduler tourne sur le trafic du
site, donc une boutique sans visiteur la nuit est régulièrement en retard de
quelques heures sans que rien n'aille mal. Une alerte qui sonne pour ça apprend
à son lecteur à l'ignorer. Un filtre peut l'allonger ; il ne peut pas le mettre
à zéro, et une assertion nommée garde ce plancher.

### Preuve

`_bench/prove-deferred-sepa.py` pose **13 violations délibérées** — délai de
grâce supprimé, tentative ignorée, autre passerelle balayée, commande sans
échéance signalée, balayage qui se répète, un courriel par commande, empreinte
sans date, empreinte écrite après l'envoi, refus muet, balayage qui prélève,
planification qui refuse, note muette, liste du plus récent au plus vieux — et
exige que chacune fasse tomber l'assertion nommée qu'elle vise. Treize sur
treize. Cinq d'entre elles ne tombaient pas au premier essai : à chaque fois
c'était l'assertion ou la fixture qui ne mesurait pas ce qu'elle prétendait —
une seule commande en retard dans le lot (donc « un seul courriel » était vrai
par accident), le refus testé en appelant l'alerte directement au lieu de
passer par `refuse()`, le délai de grâce vérifié comme une constante au lieu
d'être vérifié contre un filtre qui tente de le supprimer.

## 2 ter. Un prélèvement envoyé qui n'aboutit pas, écrit le 2026-10-20

Le 2 bis couvrait le prélèvement qui n'a **jamais tourné**. Celui-ci couvre
l'autre moitié, plus chère : le prélèvement a été envoyé, Stripe l'a accepté,
la marchandise est partie, et ça ne tient pas.

Le chantier est parti d'une question de Hugo (« les options relancer hors
stripe/sepa, reprogrammer… vérifie que tout cela n'a pas un intérêt pour notre
version aussi ») et d'une **limite connue** notée dans la version Annad en
1.8.3 : une commande déjà envoyée à Stripe puis rejetée par la banque est bien
en retard, mais n'est pas « non exécutée », donc aucune relance ne lui est
proposée.

### L'impasse, qui était le vrai défaut

En lisant cette limite chez Annad, on trouve la même chez nous, en pire. Le
chemin de prélèvement appelle `dahu_deferred_sepa_mark_done()` dès que Stripe
**accepte l'intention** — statut `processing`, c'est-à-dire « en route », pas
« encaissé ». Et `_dahu_deferred_sepa_done` est ce que toutes les gardes lisent
comme « un prélèvement est confirmé » :

- `dahu_deferred_sepa_charge()` sort immédiatement ;
- la replanification est refusée ;
- `dahu_deferred_sepa_mark_settled_offline()` refuse le marquage, et l'écran de
  commande ne dessine même pas le formulaire.

Donc une commande envoyée puis refusée **avant encaissement** n'avait plus
aucune issue : ni prélèvement, ni replanification, ni « réglé autrement ». Rien
n'avait quitté le compte du client, la boutique n'était pas payée, et il n'y
avait aucun bouton.

### Ce qui a été fait

Le `switch` du webhook distingue maintenant trois cas sur
`payment_intent.payment_failed`, selon le résultat **lu avant** d'être écrasé :

| État précédent | Ce qui s'est passé dans le monde | Ce qu'on fait |
| --- | --- | --- |
| `processing` (ou `_done`) | Refusé **avant** encaissement : rien n'a quitté le compte du client | `_done` est **retiré** (la commande est réouverte), résultat `failed`, et l'alerte de **refus** ordinaire part — son « corrigez et replanifiez » est alors vrai *et* réalisable |
| `succeeded` | Ambigu : vrai retour bancaire, ou doublon pour l'intention qui a abouti | **rien n'est touché** — défaire un encaissement sur un événement ambigu est le seul geste qui pourrait coûter de l'argent — mais une note est écrite et une alerte part |
| ni l'un ni l'autre | Échec au moment de l'envoi | **aucun courriel** : le chemin de prélèvement a déjà écrit à la boutique. Dit au journal, parce qu'« aucun courriel ici » ressemble à un bogue quand quelqu'un vient vérifier |

`_dahu_deferred_sepa_executed_ts` n'est **jamais** retiré : une tentative a bien
eu lieu, l'historique est borné dessus, et l'effacer masquerait la tentative.

`charge.dispute.created` alerte sans condition : un litige n'existe que sur de
l'argent qui a été pris, et aucun autre chemin de l'extension ne le signale.

### Ce que la revue adverse a cassé, et c'est l'essentiel

La première version de ce travail a été entièrement refaite après revue. Trois
défauts, tous réels, tous vérifiés dans le code avant correction :

1. **Les deux courriels donnaient une consigne que le code refuse.** Ils
   disaient « encaissez-le autrement et enregistrez-le sur la commande ». Or
   `mark_settled_offline()` refuse un prélèvement confirmé et l'écran ne dessine
   pas le formulaire. C'était exactement le reproche que le docblock de
   l'alerte de refus adresse au courriel de refus — reproduit sur l'autre route.
   Un conseil que le code refuse envoie quelqu'un chercher un bouton absent.
2. **« L'argent a bougé » était faux pour un refus depuis `processing`.**
   `stripe_confirmed()` compte `processing` comme confirmé, ce qui est juste
   pour sa question d'origine (peut-on effacer un prélèvement en vol ?) et faux
   pour celle-ci. Le courriel annonçait un retour bancaire sur une créance
   parfaitement récupérable, et présentait la commande comme intouchable.
   C'est ce défaut qui a mené à l'impasse ci-dessus, donc à la vraie
   correction : le webhook ne consulte plus `stripe_confirmed()` du tout, il
   branche sur le résultat précédent. Effet de bord utile : la décision ne
   dépend plus de `_stripe_charge_id`, une métadonnée que **cette extension
   n'écrit jamais** — elle appartient à l'extension Stripe officielle.
3. **L'empreinte de déduplication était constante par commande.**
   `dahu_deferred_sepa_alert_fingerprint()` mélange
   `_dahu_deferred_sepa_scheduled_ts` pour qu'une nouvelle échéance refasse une
   alerte — mais `mark_done()` **supprime** cette métadonnée, et à l'instant
   d'une réversion le prélèvement est toujours fait. La date valait donc 0 pour
   toujours, et un second rejet sur un cycle ultérieur aurait été avalé comme
   un doublon du premier. L'horodatage de tentative est maintenant passé
   explicitement.

**Le même défaut existait, préexistant, sur le chemin de refus**, et il a été
corrigé dans la même session parce qu'il est pire : `dahu_deferred_sepa_refuse()`
supprime `_scheduled_ts` *avant* d'appeler `alert_failure()`. L'empreinte de
refus valait donc `|0|`, et un prélèvement refusé, corrigé, replanifié, puis
refusé **pour la même raison** n'était dit à personne. Un refus silencieux est
exactement ce que ce fichier existe pour empêcher : le chien de garde couvrait
le silence qu'il surveillait.

La correction tient dans `dahu_deferred_sepa_alert_fingerprint()`, en trois
clés et dans cet ordre :

    $cycle = (int) $order->get_meta( '_dahu_deferred_sepa_scheduled_ts' );
    if ( ! $cycle ) {
        $cycle = (int) $order->get_meta( '_dahu_deferred_sepa_final_scheduled_ts' );
    }
    if ( ! $cycle ) {
        $cycle = (int) $order->get_meta( '_dahu_deferred_sepa_executed_ts' );
    }

et `dahu_deferred_sepa_refuse()` recopie l'échéance sous `_final_scheduled_ts`
**avant** de la supprimer, comme `mark_done()` le faisait déjà. Les trois cas
sont nécessaires :

- le balayage des retards alerte sur des prélèvements qui n'ont **jamais**
  tourné : il n'y a pas de tentative, et c'est l'échéance vivante qui distingue
  « en retard le 14 » de « en retard de nouveau le 21 » ;
- tous les chemins qui alertent sur un prélèvement qui a **bien** tourné ont
  déjà supprimé l'échéance au moment d'alerter (`refuse()` comme `mark_done()`),
  et c'est la date du cycle conservée qui les distingue ;
- l'horodatage de tentative ne vient qu'en **dernier** recours. Première version
  écrite : il venait en premier, et le banc l'a refusée — `refuse()` écrit
  `_executed_ts = time()`, donc deux refus dans la **même seconde** partagent
  l'empreinte. Improbable en production, trivial au banc, et une boucle de
  reprise le produirait aussi.

Les deux moitiés sont mesurées : section 15 pour le balayage (« une
replanification fait réalerter ») et section 15 pour le refus (« UN SECOND REFUS
APRÈS REPRISE EST DIT AUSSI », avec son contrôle en négatif « MAIS LE MÊME REFUS
DANS LE MÊME CYCLE NE REPART PAS »).

### Deux choses connues et laissées en place

- `update_status( 'failed', … )` sur une commande Terminée déclenche le
  courriel « Commande échouée » de WooCommerce vers l'administrateur, qui
  contient l'adresse de facturation complète. La boutique reçoit donc deux
  courriels pour un seul événement, et c'est celui de WooCommerce — pas le
  nôtre — qui transporte des données personnelles. Préexistant, désactivable
  dans les réglages WooCommerce, non modifié : supprimer un courriel standard
  de WooCommerce en silence est une décision qui appartient à la boutique.
- Une commande réouverte n'a plus de date d'échéance (`mark_done()` l'avait
  supprimée). Elle n'est donc pas reprise par la surveillance des retards, qui
  exige une date passée. Elle apparaît dans « À venir » sans date, et le
  courriel qui vient de partir est ce qui la signale.

### Ce qui n'a pas été porté, et pourquoi

Annad a déplacé en 1.8.3 les actions de rattrapage vers l'écran « À venir »,
parce que chez lui « Reprogrammer » n'était offert que dans l'Historique alors
que l'alerte se lisait dans « À venir » : l'action n'était pas là où l'alerte
était vue.

Ce découpage-là n'existe pas chez nous. Nos trois actions — prélever, replanifier,
régler autrement — sont **au même endroit**, sur la commande, et le bloc des
retards de l'écran « À venir » donne un lien direct vers chaque commande
concernée. L'action est donc à un clic de l'alerte, dans un seul endroit, et
dupliquer la surface d'action n'achèterait rien.

### Le découpage du gestionnaire de webhook

`dahu_deferred_sepa_apply_event( $order, $type, $object )` a été extraite du
gestionnaire. La coupure est posée à un endroit précis : le gestionnaire garde
tout ce qui décide si un événement est **croyable** — signature, propriété,
site, intention — et la fonction extraite porte ce que l'extension **fait** de
l'événement.

Deux conséquences, et la seconde est la raison du découpage. Le gestionnaire
peut refuser un événement sans que la fonction soit atteinte, donc ces
vérifications restent incontournables. Et les décisions deviennent mesurables
seules, ce qui était impossible autrement : **le banc tourne sans secret de
webhook, à dessein** — la section 4 affirme qu'« un point d'entrée non
configuré n'accepte rien » — donc toute signature que le banc peut fabriquer
est refusée avant le `switch`. La première version de la section 18 appelait le
gestionnaire complet, et ses treize assertions sont tombées toutes ensemble
pour cette seule raison.

Ce n'est pas un crochet de test : `apply_event()` ne prend aucune décision
d'authenticité, et son docblock dit de ne l'appeler qu'avec un événement déjà
vérifié.

Ce que le découpage ne change pas, et qu'il faut savoir : les gardes du
gestionnaire — propriété de la charge, site d'origine, correspondance de
l'intention — restent couvertes **unitairement** seulement
(`dahu_deferred_sepa_resolve_webhook_order()`,
`dahu_deferred_sepa_event_intent()`, `dahu_deferred_sepa_verify_webhook()`) et
jamais de bout en bout. La cause est structurelle : le secret est une
**constante** (`DAHU_DEFERRED_SEPA_WEBHOOK_SECRET`), non filtrable, vide au
banc, donc aucun appel au gestionnaire ne peut franchir la signature. C'était
déjà le cas avant le découpage — il n'a rien retiré, il a rendu l'autre moitié
mesurable. Si on voulait un jour couvrir ces gardes en bout en bout, il
faudrait un filtre sur le secret, ce qui est précisément le genre de crochet
qu'on ne pose pas sur un contrôle de signature.

### Les courriels de WooCommerce comptés comme les nôtres

`update_status( 'failed' )` fait partir le courriel « Commande échouée » de
WooCommerce, et un passage en « Terminée » celui du client. Le banc capte
`pre_wp_mail`, donc compter tout ce qu'il capte revient à compter les courriels
de WooCommerce comme les nôtres : trois assertions de la section 18 sont tombées
là-dessus. `ds_mail_ours()` ne compte que les objets contenant « SEPA ».

C'est accessoirement la mesure de ce qui est signalé plus haut : pour un seul
rejet, la boutique reçoit bien deux courriels, le nôtre et celui de WooCommerce.

### Le piège du banc qui a coûté le plus de temps

Les scripts `prove-*.py` copient l'extension de côté avant d'injecter, et la
remettent en place à la sortie. Ils ne **supprimaient pas** la copie de côté.

Un script arrêté sur un banc rouge laissait donc son instantané sur le disque,
et le script suivant le restaurait **avant de commencer** : l'extension
revenait à l'état qu'elle avait au début du run précédent, effaçant le code
écrit entre les deux. Le rouge qui en sort désigne autre chose que la cause, et
la suite lancée à la main juste avant était verte — de quoi chercher longtemps.

Corrigé dans les sept scripts : toute sortie normale supprime l'instantané, et
celui d'un run interrompu est restauré **puis** supprimé.

### Suite de tests

Section 18 de `_bench/t-deferred-sepa.php`. Le gestionnaire de webhook est
appelé **pour de vrai**, signature comprise, par `ds_webhook()` : la décision
mesurée est celle du `switch`, pas celle d'une fonction d'alerte appelée à la
main.

La mesure centrale prouve que l'impasse est levée en exerçant ce que le code
refusait : `mark_settled_offline()` rend une `WP_Error` avant le webhook, et
n'en rend plus après.

`_bench/prove-deferred-sepa.py` compte 32 injections — **32 attrapées, 0
manquée**, mesuré le 2026-10-20 sur une suite de 321 assertions — dont 6 pour
cette section et 2 pour l'empreinte : réouverture jamais faite, réouverture faite à tort sur un encaissement,
réouverture sur une commande jamais envoyée, échec après règlement passé sous
silence, contestation passée sous silence, commande réouverte sans le dire,
empreinte adossée à une date effacée, et litige confondu avec un rejet.

## 3. Ce qui reste à faire

Aucun de ces points ne peut être couvert depuis le banc : ils demandent un
compte Stripe en mode test.

- [ ] **Bout en bout en mode test.** IBAN de test Stripe `AT611904300234573201` :
      enregistrer le mandat, l'approuver, passer commande, passer la commande en
      « Terminé », vérifier l'action planifiée, la déclencher, vérifier le
      PaymentIntent côté Stripe.
- [ ] **Webhook.** Vérifier qu'une signature valide passe, qu'une signature
      fausse est refusée en 400, et qu'un événement hors périmètre répond 200
      sans toucher à la commande.
- [ ] **Idempotence.** Rejouer deux fois la même action planifiée et vérifier
      chez Stripe qu'il n'y a qu'un seul PaymentIntent.
- [ ] **Devise.** Commande en USD : vérifier le refus au checkout et la note.
- [ ] **Repli de résolution du mandat** (§1.5 n°2) : commande passée via la
      passerelle SEPA officielle, sans `_stripe_source_id`, puis prélèvement.
- [ ] **Historique à l'échelle.** L'écran est maintenant paginé par la base ;
      vérifier le temps de réponse sur quelques milliers de commandes, et que le
      tri par montant et par numéro donne le même ordre qu'avant.
- [ ] **Les deux écrans de commande**, HPOS et legacy.
- [ ] **Masquage du SEPA natif** dans les deux modes de checkout Stripe
      (classique et « Optimized Checkout »), réglage activé puis désactivé.

---

## 4. Points d'architecture à connaître

### 4.1 La commande est « en attente », pas « en cours »

`process_payment()` met la commande en `on-hold`. C'est délibéré : la commande
doit être préparée et expédiée, mais elle **n'est pas payée**. La mettre en
`processing` la ferait compter comme payée dans tous les rapports de la
boutique.

Corollaire : `$order->is_paid()` n'est pas une source de vérité pour cette
passerelle. Les deux endroits qui testent « déjà payée » excluent explicitement
`DAHU_DEFERRED_SEPA_GATEWAY_ID` avant d'appeler `is_paid()`. C'est subtil et
facile à casser.

### 4.2 La clé d'idempotence est la ligne la plus importante

`get_idempotency_key()` génère une clé une seule fois, la stocke sur la
commande, et la réutilise. Tous les rejeux — timeout réseau, relance Action
Scheduler, clic sur « Charge now » pendant que l'action tourne — portent la même
clé, donc Stripe ne débite qu'une fois. Ne jamais la régénérer.

### 4.3 Deux routes de prélèvement, dans cet ordre

1. Un PaymentIntent existant en `requires_confirmation` est confirmé.
2. Sinon, un nouveau PaymentIntent off-session est créé et confirmé.

La route 1 est un héritage de la stratégie d'interception abandonnée (§1.3) :
elle ne se déclenche plus en pratique, puisque rien ne laisse plus de
PaymentIntent dans cet état. Elle est conservée parce qu'elle est inoffensive et
qu'elle couvre une commande dont le PaymentIntent aurait été créé autrement.

### 4.4 L'emprunt de la clé Stripe reste une dette, assumée

`get_secret_key()` lit `woocommerce_stripe_settings` quand la passerelle n'a pas
sa propre clé. C'est l'option interne d'une autre extension, pas une API
publique — exactement le type de dépendance qui a coûté 667 lignes à
`dahu-post` avec Colissimo.

Trois choses rendent la dette acceptable ici :

1. Le réglage propre à la passerelle est **proposé en premier** et documenté, et
   une clé restreinte suffit. Une boutique qui le remplit n'a aucune dépendance.
2. Si l'option change de forme, `get_secret_key()` renvoie `''`, le prélèvement
   n'est pas tenté, et la note de commande le dit. **Jamais de repli silencieux
   sur une mauvaise clé.**
3. C'est le mode de test de la **même extension officielle** qui décide du mode,
   pas le nôtre : la clé doit correspondre au mode dans lequel le mandat a été
   créé.

### 4.5 Le filtre `wc_stripe_upe_params` est un vrai point d'extension

C'est le seul endroit où cette extension s'accroche à un filtre du plugin Stripe
officiel. Contrairement aux filtres devinés retirés en §1.3, celui-ci a été
vérifié dans le code source du plugin
(`includes/payment-methods/class-wc-stripe-upe-payment-gateway.php`, méthode
`javascript_params()`). S'il disparaît un jour, la fonction renvoie les
paramètres inchangés et la seule conséquence est que les deux options SEPA
s'affichent.

### 4.6 Le select de moyen de paiement de la fiche commande n'a pas de filtre

`WC_Meta_Box_Order_Data::output()` construit ce select à partir de **toutes** les
passerelles enregistrées et activées, sans filtre dédié. D'où une paire :

- **affichage** : un script retire l'option, sauf si c'est le moyen de paiement
  **actuel** de la commande — sinon les commandes historiques afficheraient
  « Autre » ;
- **verrou serveur** : `block_native_sepa_selection()`, en priorité 5 (avant le
  `save()` de WooCommerce en 40), refuse toute bascule *vers* le SEPA immédiat,
  que le script ait tourné ou non.

Le second est la vraie protection. Le premier est du confort.

---

### 4.7 `wc_get_orders()` ignore silencieusement plusieurs arguments

**À lire avant de toucher à l'écran Historique.** Mesuré sur le banc,
WooCommerce 11.1, HPOS désactivé, en comparant le nombre de résultats à celui
d'une requête sans filtre :

| Argument | Comportement réel |
|---|---|
| `meta_query` (toute forme, toute comparaison) | **ignoré** — toutes les commandes reviennent |
| `include` | **ignoré** — des commandes hors de l'ensemble reviennent |
| `id` | **ignoré** — idem |
| `orderby => 'total'` avec une borne `meta_key`/`meta_compare` | **la borne méta est perdue** — des commandes jamais prélevées apparaissent |
| `post__in` | fonctionne, mais c'est un argument `WP_Query` : indisponible dès que HPOS est actif |
| `meta_key` + `meta_value` / `meta_compare` / `meta_type` (le trio simple) | fonctionne |
| `orderby => 'meta_value_num'` ou `'ID'`, avec `meta_key` | fonctionne |
| `limit` + `paged` | fonctionne |

Aucun de ces arguments ne provoque d'erreur ni d'avertissement. Un écran bâti
sur `meta_query` **a l'air de filtrer** et ne filtre pas, ce qui est pire que
pas de filtre du tout.

D'où la forme actuelle : **un trio par condition, chacun ne demandant que des
identifiants, intersectés en PHP**. `array_intersect()` conserve l'ordre du
premier tableau, donc l'ordre survit à l'intersection. Les identifiants sont des
entiers et coûtent peu ; c'est l'instanciation des commandes qui coûte, et seule
la page consultée est instanciée. La vue sans filtre — celle qu'on obtient en
ouvrant l'écran — ne construit aucune liste et est paginée par la base.

**Conséquence assumée : la colonne Montant n'est pas triable.** Il faudrait
`orderby => 'total'`, qui perd la borne méta et listerait des commandes jamais
prélevées parmi les prélèvements. Un tri faux sur un stockage et juste sur
l'autre est pire que pas de tri, et l'export CSV répond correctement à « quels
sont les plus gros ». Quiconque voudra « réparer » ce tri avec `post__in` doit
d'abord relire ce tableau.

Le banc vérifie ces filtres **par comparaison d'ensembles**, pas en inspectant
la requête : une fenêtre de dates passée doit rendre 0, une borne basse dans le
futur doit rendre 0, une borne haute dans le futur doit rendre tout, et les
lignes rendues par un filtre de résultat doivent toutes porter ce résultat.
Neutraliser le filtrage fait échouer **sept** assertions (vérifié par injection).

---

## 5. Modèle de données

Aucune table. Tout en méta de commande, donc compatible HPOS et legacy.

| Méta de commande | Rôle | Survit au prélèvement |
|---|---|---|
| `_dahu_deferred_sepa_scheduled_ts` | date due **courante** | non, supprimée par `mark_done()` |
| `_dahu_deferred_sepa_first_scheduled_ts` | première date due, figée | oui |
| `_dahu_deferred_sepa_final_scheduled_ts` | dernière date due avant exécution | oui |
| `_dahu_deferred_sepa_reschedule_count` | nombre de déplacements | oui |
| `_dahu_deferred_sepa_executed_ts` | date d'envoi du prélèvement | oui |
| `_dahu_deferred_sepa_result` | `processing` / `succeeded` / `failed` / `disputed` | oui |
| `_dahu_deferred_sepa_result_ts` | horodatage du résultat | oui |
| `_dahu_deferred_sepa_done` | `yes` : ne plus rien tenter | oui |
| `_dahu_deferred_sepa_idem` | clé d'idempotence | oui |

Les métas lues mais écrites par le plugin Stripe officiel :
`_stripe_customer_id`, `_stripe_source_id`, `_stripe_intent_id`.

| Méta utilisateur | Rôle |
|---|---|
| `_dahu_deferred_sepa_active_token_id` | mandat approuvé par la boutique |
| `_dahu_deferred_sepa_known_default_token` | dernier défaut vu, pour détecter une soumission |
| `_dahu_deferred_sepa_delay_days` | délai propre à ce client |
| `_dahu_deferred_sepa_customer_for_pm` | cache `pm_` → `cus_`, invalidé par changement de mandat |

Côté Annad existait aussi `_dahu_deferred_sepa_authorized` : l'autorisation
commerciale était une case séparée, puis elle a été fusionnée avec le choix du
mandat actif. La constante avait d'abord été conservée « au cas où » — elle
n'était ni lue ni écrite, et suggérait un drapeau qui n'existe pas. Retirée en
revue.

---

## 6. La revue adverse, et ce qu'elle a changé

Le code a été relu par un agent qui ne l'avait pas écrit, avec pour consigne de
chercher les défauts qui coûtent de l'argent. **Le banc passait 223/223 — alors
172/172 — pendant que tout ce qui suit était ouvert.** C'est la deuxième fois sur
ce chantier qu'une revue déléguée trouve ce que les contrôles automatiques ne
voient pas ; cette fois l'écart est beaucoup plus grave.

### 6.1 Les défauts d'argent

| # | Défaut | Correctif |
|---|---|---|
| 1 | **Double prélèvement possible.** La clé d'idempotence était frappée *au moment du prélèvement*. Deux tentatives qui se chevauchent — Action Scheduler qui part pendant qu'on clique « Charge now », ou un worker tué dont l'action est reprise alors que la requête HTTP est encore ouverte (`timeout` 45 s) — trouvaient toutes deux la clé absente, en frappaient **deux différentes**, et Stripe débitait deux fois. | La clé est frappée dans `process_payment()`, donc elle existe avant toute tentative. Plus une réclamation exclusive (`dahu_deferred_sepa_claim()`, un `add_option()` que la base accepte ou refuse) autour du prélèvement, reprise au bout de 5 minutes si le worker qui la tenait est mort. |
| 2 | **Montant faux.** La route 1 confirmait un PaymentIntent déjà existant **avec un corps vide**, donc pour le montant qu'il portait déjà. Une commande modifiée après la création de l'intent était débitée de l'ancien total, puis marquée « encaissée ». | Le montant et la devise de l'intent sont comparés à ceux de la commande ; s'ils diffèrent, un nouvel intent est créé pour le total courant, et la note de commande le dit. |
| 3 | **La double condition du mandat était court-circuitée.** `find_saved_mandate()` — la seule chose qui impose « la boutique a approuvé **et** le client l'a par défaut » — n'était consultée **qu'en repli**, quand la commande ne portait ni client ni mandat. Une commande passée via la passerelle SEPA du plugin Stripe officiel sur un IBAN non approuvé était débitée sur cet IBAN. Pire, en cas de repli partiel le code mélangeait un moyen de paiement venu de la commande avec un client venu de l'approbation. | `dahu_deferred_sepa_mandate_for_order()` revérifie l'approbation **au moment du prélèvement** et prend le mandat **en entier**, jamais moitié-moitié. Si l'IBAN approuvé n'est plus celui de la commande, le prélèvement est refusé avec une note — on ne débite pas l'autre en silence. |
| 4 | **L'autorisation n'était jamais revérifiée.** Entre la commande et l'échéance, la boutique pouvait retirer son approbation et le client supprimer son IBAN : le prélèvement partait quand même. | Même correctif que le 3. |
| 5 | **Un délai de 0 jour devenait 8.** `if ( ! $days )` traitait 0 comme « non réglé ». Une boutique réglant 0 (« prélever dès l'expédition ») voyait le checkout annoncer 0 + 2 = 2 jours et le client débité le 8e. | Chaque source est testée pour être **renseignée**, pas pour être non nulle. |

### 6.2 Fuite

`wp_json_encode( $result )` partait dans le journal WooCommerce en cas de statut
inattendu. Un PaymentIntent créé porte un **`client_secret`**, qui permet de
confirmer ou d'annuler ce paiement côté client sans la clé API, et le fichier de
journal est lisible dans l'administration (État → Journaux) et présent dans
toutes les sauvegardes. Le commentaire du code expliquait justement qu'il ne
fallait pas mettre ça dans une note de commande, et choisissait une destination
pire. Seuls l'identifiant et le statut sont journalisés désormais.

Et l'export CSV : le nom et l'e-mail de facturation sont saisis par le client,
donc un client inscrit sous `=HYPERLINK(...)` faisait exécuter sa formule dans
le tableur comptable de la boutique. `dahu_deferred_sepa_csv_cell()` préfixe
une apostrophe devant `=`, `+`, `-`, `@`, tabulation et retour chariot.

### 6.3 Prélèvements silencieusement jamais faits

- Changer le moyen de paiement d'une commande après planification faisait sortir
  `charge()` **sans note ni journal**, tout en laissant `scheduled_ts` en place :
  l'onglet « À venir » comptait la commande comme recette attendue pour
  toujours.
- Après un refus Stripe, `scheduled_ts` restait posé alors que l'action était
  consommée. La récupération évidente — repasser la commande en « En cours »
  puis « Terminée » — butait sur la garde « déjà planifié » et **ne faisait
  rien, sans un mot**.

Tout refus passe maintenant par `dahu_deferred_sepa_refuse()`, qui fait trois
choses ensemble : note de commande, ligne de journal, et retrait de la date due —
ce dernier point étant ce qui rend la replanification possible.

### 6.4 L'écran Historique

- **Un prélèvement refusé était invisible.** L'ensemble est borné sur
  `_dahu_deferred_sepa_executed_ts`, que seul `mark_done()` écrivait — jamais la
  branche d'échec. Le filtre « Échoué » renvoyait donc **toujours** une table
  vide. Tout refus est désormais horodaté.
- **Le filtre de dates était en UTC** alors que l'écran affiche dans le fuseau du
  site : un prélèvement de 01h30 à Paris (23h30 UTC la veille) était affiché sous
  un jour et filtré sous un autre, et un export de fin de mois manquait les
  premières heures. `dahu_deferred_sepa_day_bound()` construit les bornes dans le
  fuseau du site.
- **Les prélèvements faits sur les passerelles SEPA officielles manquaient.** Les
  deux requêtes se restreignaient à l'identifiant de notre passerelle, alors que
  l'extension prélève aussi les commandes des autres identifiants de
  `DAHU_DEFERRED_SEPA_GATEWAYS` — et l'onglet « À venir », lui, les listait. Les
  deux onglets se contredisaient. La borne est maintenant « un prélèvement a eu
  lieu », ce que rien d'autre n'écrit.
- **La pagination sautait des lignes.** `orderby => meta_value_num` sans critère
  secondaire, sur un horodatage à la seconde, alors qu'Action Scheduler envoie un
  lot de prélèvements dans la même seconde : les requêtes des pages 1 et 2
  pouvaient diverger, montrant une ligne deux fois et jamais une autre. L'ordre
  est désormais arrêté une fois puis découpé en PHP, y compris sans filtre.
- **L'export CSV s'arrêtait en plein milieu** si un identifiant de la page ne
  s'instanciait pas (commande mise à la corbeille) : `while ( count( $rows ) ===
  200 )` terminait le fichier à 199 lignes sur 500, sans erreur. Il itère
  maintenant sur le nombre de lignes attendues.
- **Le total « attendu » additionnait des devises différentes**, dont des
  commandes qui ne peuvent jamais être prélevées. Il est désormais calculé par
  devise.

### 6.5 Le webhook

Aucune faille d'authentification trouvée : secret vide, en-tête absent, `t` ou
`v1` manquant, horodatage périmé et HMAC faux sont tous refusés, la charge signée
est bien `timestamp.corps`, et la comparaison est `hash_equals`. Trois défauts de
logique en revanche :

- **Une seule signature `v1` était lue.** Stripe en envoie **plusieurs** pendant
  une rotation de secret, et ses propres bibliothèques acceptent n'importe
  laquelle. Garder la dernière faisait rejeter **tous** les événements dès que le
  secret configuré n'était pas listé en dernier — les commandes restaient en
  `processing` indéfiniment.
- **La métadonnée était crue sur parole.** La signature prouve que l'événement
  vient de Stripe, pas qu'il concerne cette commande : `metadata` est posée par
  qui crée l'intent. Quiconque peut créer un PaymentIntent sur le compte Stripe
  de la boutique — une seconde intégration, un site de préproduction partageant
  le compte — pouvait créer un paiement de 50 centimes portant
  `order_id` et `source`, et l'événement, **légitimement signé**, marquait cette
  commande encaissée. L'identifiant d'intent doit maintenant correspondre à celui
  que l'extension a enregistré, et `site_url` est vérifié (il était posé depuis
  le début et jamais relu).
- **Un échec pouvait rétrograder une commande déjà encaissée.**
  `payment_intent.payment_failed` appelait `update_status( 'failed' )` sans
  condition.

### 6.6 Points de robustesse

`payment_intent` peut arriver en **objet étendu** sur certains litiges : le
cast en chaîne levait une alerte et perdait l'événement
(`dahu_deferred_sepa_event_intent()` lit les deux formes). Les trois filtres
d'e-mail passent par `wp_parse_args` plutôt qu'un cast nu. La metabox garde son
argument (elle est enregistrée sur un identifiant d'écran qui est aussi celui de
la **liste** des commandes). La date de la metabox est validée avant d'être
interprétée, `DateTime::createFromFormat()` acceptant `2026-13-45` en le faisant
basculer des mois plus loin. `describe_callback()` attrape `Throwable` et non
`Exception` : il tourne sur la page de paiement. Le mandat approuvé doit être un
jeton **SEPA** (une carte Stripe porte aussi le préfixe `pm_`). L'approbation
exige `edit_user` sur **ce** compte et non `edit_users` en général. Et sur
multisite, les métas utilisateur sont supprimées **une fois après** la boucle de
sites, `$wpdb->usermeta` étant une table globale au réseau : les supprimer dans
la boucle effaçait toutes les approbations de tous les sites parce qu'un seul
avait désinstallé.

### 6.7 Ce que la revue n'a pas trouvé

À noter, parce que c'est ce qui donne du poids au reste : aucune capacité ni
aucun nonce manquant sur les quatre points d'entrée qui changent l'état, aucun
XSS stocké, aucune fonction appelée sans être définie ni définie sans être
atteignable, et aucune voie de falsification du webhook.

### 6.8 Une affirmation de la revue a été corrigée

La revue affirmait qu'aucune date de paiement n'était jamais enregistrée.
**Mesuré sur le banc : WooCommerce pose `date_paid` lui-même au passage en
« Terminé »**, donc elle existe — elle vaut la date de la commande terminée et
non celle du débit, ce qui est une imprécision, pas une absence. Seul
l'identifiant de transaction n'était réellement jamais enregistré. Le correctif
et le commentaire disent maintenant cela, et pas plus.

### 6.9 Ce que le banc a gagné

**+51 assertions** (172 → 223), dont la section 12bis entière, consacrée à ces
défauts. Chacune a été prouvée par injection : remettre le court-circuit du
mandat fait échouer 4 assertions, retirer la réclamation en fait échouer 1,
cesser d'horodater un refus en fait échouer 2.

Et une donnée de test a été corrigée, parce qu'elle est la raison pour laquelle
le défaut n°B2 est passé : `ds_handle()` laissait la commande en `pending`, ce
que donne `wc_create_order()`, alors que le prélèvement réel s'exécute sur une
commande `completed`. Deux conventions en sont tirées et écrites dans
`_harness/LISEZMOI.md` — « un filtre doit rendre plus de zéro ligne et toutes
doivent correspondre », et « une donnée de test doit être dans l'état de la
production ».

---

## 7. Pourquoi ce portage a été tenté malgré le doute juridique

Hugo, le 7 octobre 2026 : « cela ne fait que différé un paiement sepa stripe je
pense que légalement ça peut se tenter. Au pire on abandonnera ce projet mais
pour le moment on le tente. »

Le point de droit n'est pas dans le code mais dans le **mandat SEPA** : selon le
schéma utilisé, le créancier doit un préavis au débiteur sur la date et le
montant de chaque prélèvement, et différer un prélèvement déplace cette date.
C'est une obligation de la boutique, pas de l'extension.

Deux choix en découlent :

1. `README.md` et `readme.txt` portent une section **« Before you switch it
   on »** qui dit cette obligation en clair, sans la minimiser et sans donner de
   conseil juridique.
2. L'extension ne promet rien au client à la place de la boutique. Les « 72h »
   de confirmation de la version Annad sont retirés (§1.4).

Si la revue wordpress.org refuse l'extension sur ce motif, le code reste valable
pour Annad et pour une diffusion hors annuaire.
