import { Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useAuthStore } from '@/lib/auth-store';

export default function Home() {
  const user = useAuthStore((s) => s.user);
  const signOut = useAuthStore((s) => s.signOut);

  const firstName = user?.name?.split(' ')[0] ?? '👋';

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <ScrollView contentContainerStyle={{ paddingBottom: 40 }}>
        {/* Header */}
        <View className="flex-row justify-between items-center px-6 pt-3 pb-1">
          <Text className="font-display-semi text-[17px] tracking-wider text-ink dark:text-cream">
            Le Cercle
          </Text>
          <Pressable onPress={signOut}>
            <Text className="font-body-medium text-[12px] text-ink-muted">Esci</Text>
          </Pressable>
        </View>

        {/* Greeting */}
        <View className="px-6 pt-2 pb-6">
          <View className="flex-row items-baseline gap-2">
            <Text className="text-[18px]">☼</Text>
            <Text className="font-body-medium text-[13px] text-ink-muted">Benvenuto,</Text>
          </View>
          <Text className="font-script text-[60px] -mt-1 text-sage-dark dark:text-sage leading-[58px]">
            {firstName}
          </Text>
        </View>

        {/* Placeholder */}
        <View className="mx-5 mb-4 rounded-[32px] p-6 bg-sage shadow-lg">
          <Text className="font-body-bold text-cream text-[11px] tracking-widest uppercase opacity-80">
            In arrivo
          </Text>
          <Text className="font-display text-cream text-[26px] mt-1 leading-tight">
            Prenotazioni, matchmaking, classifica, tornei, eventi
          </Text>
          <Text className="text-cream/80 text-[13px] mt-3 leading-relaxed">
            Sei dentro 🎾. Da qui costruiamo tutta la parità col bot WhatsApp e poi le feature app-only.
          </Text>
        </View>

        {user && (
          <View className="mx-5 rounded-3xl bg-white dark:bg-dark-surface p-5">
            <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted">
              Profilo
            </Text>
            <Text className="font-display-semi text-[20px] text-ink dark:text-cream mt-2">
              {user.name}
            </Text>
            <Text className="text-[13px] text-ink-muted font-body-medium mt-1">
              {user.phone}
            </Text>
            <View className="flex-row gap-3 mt-3">
              <View>
                <Text className="font-display-semi text-[20px] text-ink dark:text-cream">{user.elo_rating}</Text>
                <Text className="text-[10px] uppercase tracking-widest text-ink-muted font-body-bold">ELO</Text>
              </View>
              <View>
                <Text className="font-display-semi text-[20px] text-ink dark:text-cream">{user.matches_played}</Text>
                <Text className="text-[10px] uppercase tracking-widest text-ink-muted font-body-bold">Partite</Text>
              </View>
            </View>
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}
