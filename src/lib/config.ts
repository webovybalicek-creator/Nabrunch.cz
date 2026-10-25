export const SITE = {
  nazev: 'naBrunch.cz',
  domena: 'nabrunch.cz',
  url: 'https://nabrunch.cz',
  email: 'karolina@nabrunch.cz',
  instagram: 'https://www.instagram.com/nabrunch.cz/',
  facebook: 'https://www.facebook.com/profile.php?id=61574883838185',
  /**
   * Dokud je false, všechny stránky mají noindex a robots.txt blokuje roboty
   * (web běží na new.nabrunch.cz vedle WordPressu). Přepnout na true při spuštění na nabrunch.cz.
   */
  indexovat: false,
  /**
   * Google AdSense (ID vydavatele) a Google Analytics 4 (ID měření).
   * Načítají se jen na ostré doméně nabrunch.cz (ne na new.nabrunch.cz), výchozí souhlas je „denied“ (Consent Mode v2).
   * Souhlas s cookies zajišťuje CMP Googlu: AdSense → Ochrana soukromí a zprávy → Evropské předpisy (GDPR).
   */
  adsense: 'ca-pub-3411965691537686',
  analytics: 'G-BGXQWFHPS5',
};
