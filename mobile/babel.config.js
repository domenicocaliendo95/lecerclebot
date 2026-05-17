module.exports = function (api) {
  api.cache(true);
  return {
    presets: [
      ['babel-preset-expo', { jsxImportSource: 'nativewind' }],
      'nativewind/babel',
    ],
    // Reanimated 4 ha estratto i worklet in un pacchetto separato.
    // Il plugin DEVE essere l'ultimo della lista.
    plugins: ['react-native-worklets/plugin'],
  };
};
