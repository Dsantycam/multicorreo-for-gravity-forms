=== Multicorreo for Gravity Forms ===
Contributors: Dsantycam
Tags: gravity forms, notifications, email, round robin, random
Requires at least: 5.0.0
Tested up to: 6.9
Requires PHP: 7.0
Stable tag: 1.0.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Reparte las notificaciones de Gravity Forms entre listas de correos, por cupos,
de forma equitativa y sin perder ningún envío.

== Description ==

Extensión para **Gravity Forms** que, en vez de enviar una notificación siempre al
mismo correo, la reparte entre una lista de correos.

**Cómo funciona:**

* **Listas sin límite (Multicorreo > Listas de correos):** crea todas las listas que
  quieras, con todos los correos que quieras, uno por línea.
* **Cupos:** un correo puede aparecer varias veces en la lista. Cada aparición es un
  envío más por ronda: si ventas1@ aparece 3 veces y ventas2@ 1 vez, de cada 4 envíos
  3 van a ventas1@ y 1 a ventas2@.
* **En cada notificación:** en «Enviar a» aparece la opción «Reparto aleatorio
  (Multicorreo)». Eliges la lista y listo. Cualquier notificación de cualquier
  formulario puede usar cualquier lista.
* **Reparto equitativo (bolsa barajada):** cada formulario lleva su propia ronda. La
  ronda empieza con todos los cupos barajados; cada envío saca el siguiente y ninguno
  se repite hasta agotar la ronda. Luego se baraja de nuevo. Es atómico: dos envíos
  simultáneos nunca se llevan el mismo cupo.
* **Misma persona, mismo correo (12 horas):** si alguien vuelve a enviar el
  formulario con el mismo email en menos de 12 horas, le llega al mismo correo que
  la primera vez y no se gasta otro cupo. Solo se guarda una huella cifrada (hash)
  del email, que se borra sola.
* **Ningún envío se pierde:** si el correo falla, se reintenta solo al mismo correo
  asignado a los 5 min, 15 min, 1 h, 3 h, 6 h y 12 h. Si sigue fallando, un aviso en
  el escritorio lo indica y se puede reintentar a mano.
* **El spam no gasta cupos:** Gravity Forms no envía notificaciones de las entradas
  marcadas como spam.
* **Queda registrado en cada entrada:** columna «Multicorreo: asignado a» en la lista
  de entradas (con filtro) y una nota en la entrada. Si reenvías la notificación desde
  el admin, va al mismo correo y no gasta cupo.
* **Etiqueta {multicorreo}:** se sustituye por el correo que le tocó a ese envío. Úsala
  en la confirmación o en el correo al cliente («Te atenderá {multicorreo}»).
* **Panel de reparto (Multicorreo > Reparto):** envíos repartidos por correo y por día,
  la parte que recibe cada correo frente a la que le corresponde por sus cupos, la
  ronda actual de cada formulario, los envíos pendientes y exportación a CSV.
* **Permisos (Multicorreo > Permisos):** elige qué roles, además de los
  administradores, gestionan las listas y ven el panel.

Si se cambian los correos de una lista, sus rondas empiezan de nuevo con la lista
nueva. Si se borra una lista que estaba en uso, el correo se envía al administrador
del sitio y la entrada lo explica (por eso no se puede borrar una lista en uso).

== Installation ==

1. Necesita **Gravity Forms 2.5** o superior.
2. Sube e instala este plugin y actívalo.
3. Ve a **Multicorreo > Listas de correos** y crea una lista.
4. Edita la notificación de un formulario y en «Enviar a» elige «Reparto aleatorio
   (Multicorreo)» y la lista.

== Auto-actualización ==

Las actualizaciones se descargan desde GitHub
(https://github.com/Dsantycam/multicorreo-for-gravity-forms). Para publicar una
actualización, crea una «Release» en GitHub con la nueva versión (la misma que en la
cabecera del plugin y en «Stable tag»).

== Frequently Asked Questions ==

= ¿El reparto es exactamente proporcional? =
Al completar cada ronda, sí: cada correo recibe exactamente sus cupos. A mitad de
ronda, o si se cambia la lista, puede haber pequeñas diferencias.

= ¿Qué pasa con los reintentos si cambio la lista? =
Los envíos pendientes se mantienen con el correo que ya tenían asignado.

= ¿Funciona con plugins SMTP? =
Sí. El plugin usa el envío normal de Gravity Forms (wp_mail), así que cualquier
plugin SMTP sigue funcionando igual.

= Filtros para desarrolladores =
* `mcgf_sticky_hours` (12): horas de «misma persona, mismo correo». 0 lo desactiva.
* `mcgf_retry_delays`: esperas entre reintentos, en segundos.
* `mcgf_stats`: devuelve false para no guardar estadísticas.

== Changelog ==

Ver changelog.txt
