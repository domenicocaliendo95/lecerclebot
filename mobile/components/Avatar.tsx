import { Image, Text, View } from 'react-native';

type Props = {
  url?: string | null;
  name?: string | null;
  size?: number;
  bordered?: boolean;
  className?: string;
};

export function Avatar({ url, name, size = 38, bordered = false, className = '' }: Props) {
  const initial = (name ?? '?').trim().charAt(0).toUpperCase() || '?';

  if (url) {
    return (
      <Image
        source={{ uri: url }}
        style={{
          width: size,
          height: size,
          borderRadius: size / 2,
          borderWidth: bordered ? 2 : 0,
          borderColor: '#D8CEB6',
        }}
      />
    );
  }

  return (
    <View
      className={className}
      style={{
        width: size,
        height: size,
        borderRadius: size / 2,
        backgroundColor: '#6B8068',
        alignItems: 'center',
        justifyContent: 'center',
        shadowColor: '#6B8068',
        shadowOpacity: 0.25,
        shadowRadius: 12,
        shadowOffset: { width: 0, height: 4 },
      }}
    >
      <Text
        style={{
          fontFamily: 'Fraunces_600SemiBold',
          fontSize: size * 0.4,
          color: '#ECE3CE',
        }}
      >
        {initial}
      </Text>
    </View>
  );
}
