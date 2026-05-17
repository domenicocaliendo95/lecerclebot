/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./app/**/*.{js,jsx,ts,tsx}', './components/**/*.{js,jsx,ts,tsx}'],
  presets: [require('nativewind/preset')],
  theme: {
    extend: {
      colors: {
        // Brand
        sage: {
          DEFAULT: '#6B8068',
          dark: '#4F6450',
          light: '#8AA086',
          50: '#F2F4F1',
        },
        cream: {
          DEFAULT: '#ECE3CE',
          dark: '#D8CEB6',
          light: '#FAF4E6',
        },
        ocra: {
          DEFAULT: '#C89B5A',
          dark: '#A47A3F',
          light: '#DDB47A',
        },
        // Semantic
        success: '#5C8A5E',
        warning: '#C89B5A',
        danger: '#A85B4F',
        // Text
        ink: {
          DEFAULT: '#1F2419',
          secondary: '#3A4036',
          muted: '#7A7E72',
        },
        // Dark theme
        'dark-bg': '#1A211D',
        'dark-surface': '#243029',
        'dark-elevated': '#2D3A33',
      },
      fontFamily: {
        display: ['Fraunces_500Medium'],
        'display-italic': ['Fraunces_500Medium_Italic'],
        'display-semi': ['Fraunces_600SemiBold'],
        'display-bold': ['Fraunces_700Bold'],
        body: ['Manrope_400Regular'],
        'body-medium': ['Manrope_500Medium'],
        'body-semi': ['Manrope_600SemiBold'],
        'body-bold': ['Manrope_700Bold'],
        script: ['Italianno_400Regular'],
      },
      borderRadius: {
        '2xl': '20px',
        '3xl': '24px',
        '4xl': '32px',
      },
    },
  },
  plugins: [],
};
