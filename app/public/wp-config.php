<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'local' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'root' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );
define('AR_SMTP_HOST','smtp.gmail.com');
define('AR_SMTP_PORT',587);
define('AR_SMTP_SECURE','tls');
define('AR_SMTP_USER','gaddammanishreddy0@gmail.com');
define('AR_SMTP_PASS','sszllsvxggqknhcm');
define('AR_SMTP_FROM','gaddammanishreddy0@gmail.com');


/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          '!z55<.**ia,bWT:X|he|-);7v0}@M[h?UGdqMNH*}Eook&681{Y} flw1R .c9H@' );
define( 'SECURE_AUTH_KEY',   ' c1=JulC+@o,B$KcU8#xU;=!>v_.mgsV!OLK {<B]Vb[p,F8@@ &z+$cpo8&hE$J' );
define( 'LOGGED_IN_KEY',     'X6@[/X}q2U)*xvZR|bdJ>j-0h!1Uq109Fz0)=VbO|@J^Ik7k7,Os#as?#kv|DkP8' );
define( 'NONCE_KEY',         '>EpaI=f$}s%ek7&[T6Ff6mS>|`H;h*:F*,CPUa`QSeQ~O4x3f5[2hz~j2=J^s$1v' );
define( 'AUTH_SALT',         't_+jt0` W?ihD*[:ROoMk6ly=toh2Ujz#n.hVk8BKl>%XFh}%qnB(m6Rw53Tv2KN' );
define( 'SECURE_AUTH_SALT',  'f4+j5Sch-mE8p8@?z&yU6,hda:0hJ @9wES~P@2n*%VRcnEH yps0|Mau~w>0<aG' );
define( 'LOGGED_IN_SALT',    'DlW[&<}6O!__dYN9>{4OAN)X;#~r2l][*YY1Scf!lv[sDA.S!j:Sba>kbS=g</C1' );
define( 'NONCE_SALT',        'oi``lzGK{r?g?*uXn%l,jYUbIHxco=r)EN$pER.s5*x]WD<GDpIjv<[:A{}mVfR%' );
define( 'WP_CACHE_KEY_SALT', 'SL[+0:DgY/{:m:E>tv2Y6[/WWh*o:>*ALZF%gY}PMIjx9$Hn23SS-ye=4ABw?U>~' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

define( 'WP_ENVIRONMENT_TYPE', 'local' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
