/**
 * Formattazioni italiane per date, orari, prezzi.
 */

const ITALIAN_DAYS = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
const ITALIAN_DAYS_SHORT = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
const ITALIAN_MONTHS = [
  'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
  'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre',
];
const ITALIAN_MONTHS_SHORT = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

export function greeting(date = new Date()): string {
  const h = date.getHours();
  if (h < 6)  return 'Buonanotte';
  if (h < 12) return 'Buongiorno';
  if (h < 18) return 'Buon pomeriggio';
  return 'Buonasera';
}

export function firstName(name: string | null | undefined): string {
  return (name ?? '').trim().split(/\s+/)[0] || '👋';
}

export function dateFull(d: Date | string): string {
  const date = typeof d === 'string' ? parseDate(d) : d;
  return `${ITALIAN_DAYS[date.getDay()]} ${date.getDate()} ${ITALIAN_MONTHS[date.getMonth()]}`;
}

export function dateShort(d: Date | string): string {
  const date = typeof d === 'string' ? parseDate(d) : d;
  return `${ITALIAN_DAYS_SHORT[date.getDay()]} ${date.getDate()} ${ITALIAN_MONTHS_SHORT[date.getMonth()]}`;
}

export function dateRelative(d: Date | string, now = new Date()): string {
  const date = typeof d === 'string' ? parseDate(d) : d;
  const today    = stripTime(now);
  const target   = stripTime(date);
  const diffDays = Math.round((target.getTime() - today.getTime()) / 86_400_000);

  if (diffDays === 0)  return 'Oggi';
  if (diffDays === 1)  return 'Domani';
  if (diffDays === -1) return 'Ieri';
  if (diffDays > 1 && diffDays < 7)  return `${ITALIAN_DAYS[date.getDay()]}`;
  if (diffDays < -1 && diffDays > -7) return `${ITALIAN_DAYS[date.getDay()]} scorso`;
  return dateShort(date);
}

export function timeUntil(d: Date | string, now = new Date()): string {
  const date = typeof d === 'string' ? parseDate(d) : d;
  const diffMs = date.getTime() - now.getTime();
  if (diffMs < 0) return '';
  const mins = Math.floor(diffMs / 60_000);
  if (mins < 60)  return `in ${mins} min`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `in ${hours}h`;
  const days = Math.floor(hours / 24);
  return `in ${days}g`;
}

export function money(value: number, currency = '€'): string {
  return `${currency}${value.toFixed(0)}`;
}

function parseDate(s: string): Date {
  // Supporta "YYYY-MM-DD" e "YYYY-MM-DDTHH:mm" e ISO completo
  if (s.length === 10) return new Date(s + 'T00:00:00');
  return new Date(s);
}

function stripTime(d: Date): Date {
  return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}
