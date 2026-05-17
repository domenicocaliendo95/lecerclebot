/**
 * Riviera Design System — Le Cercle Club
 * Sage + cream + ocra. Fraunces / Manrope / Italianno.
 */

export const palette = {
  light: {
    brand: {
      primary: '#6B8068',
      primaryDark: '#4F6450',
      primaryLight: '#8AA086',
      cream: '#ECE3CE',
      creamDark: '#D8CEB6',
      creamLight: '#FAF4E6',
    },
    accent: {
      DEFAULT: '#C89B5A',
      light: '#DDB47A',
      dark: '#A47A3F',
    },
    bg: '#FAF4E6',
    surface: '#FFFFFF',
    surfaceElevated: '#FFFFFF',
    border: 'rgba(31, 36, 25, 0.06)',
    divider: 'rgba(31, 36, 25, 0.08)',
    text: {
      primary: '#1F2419',
      secondary: '#3A4036',
      muted: '#7A7E72',
      onBrand: '#ECE3CE',
    },
    semantic: {
      success: '#5C8A5E',
      warning: '#C89B5A',
      danger: '#A85B4F',
    },
  },
  dark: {
    brand: {
      primary: '#8AA086',
      primaryDark: '#6B8068',
      primaryLight: '#A8BBA4',
      cream: '#ECE3CE',
      creamDark: '#C2B89E',
      creamLight: '#FAF4E6',
    },
    accent: {
      DEFAULT: '#DDB47A',
      light: '#E8C898',
      dark: '#C89B5A',
    },
    bg: '#1A211D',
    surface: '#243029',
    surfaceElevated: '#2D3A33',
    border: 'rgba(236, 227, 206, 0.08)',
    divider: 'rgba(236, 227, 206, 0.10)',
    text: {
      primary: '#ECE3CE',
      secondary: '#C2B89E',
      muted: '#8A8E82',
      onBrand: '#1F2419',
    },
    semantic: {
      success: '#7BAB7D',
      warning: '#D6A66D',
      danger: '#C77367',
    },
  },
} as const;

export const radii = {
  sm: 12,
  md: 16,
  lg: 20,
  xl: 24,
  '2xl': 32,
  full: 9999,
} as const;

export const spacing = {
  0: 0, 1: 4, 2: 8, 3: 12, 4: 16, 5: 20, 6: 24, 8: 32, 10: 40, 12: 48, 16: 64,
} as const;

export type Theme = 'light' | 'dark';
export type Palette = typeof palette[Theme];
