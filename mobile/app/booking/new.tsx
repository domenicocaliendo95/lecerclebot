import { Pressable, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router } from 'expo-router';
import { Calendar, ChevronLeft } from 'lucide-react-native';

export default function NewBooking() {
  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <View className="flex-row items-center justify-between px-5 pt-3 pb-2">
        <Pressable onPress={() => router.back()} className="w-10 h-10 items-center justify-center -ml-1">
          <ChevronLeft size={26} color="#1F2419" strokeWidth={1.5} />
        </Pressable>
        <Text className="font-display-italic text-[18px] text-ink dark:text-cream">Prenota</Text>
        <View className="w-10" />
      </View>

      <View className="flex-1 items-center justify-center px-10">
        <View
          className="w-20 h-20 rounded-full bg-white dark:bg-dark-surface items-center justify-center mb-4"
          style={{ shadowColor: '#6B8068', shadowOpacity: 0.12, shadowRadius: 20, shadowOffset: { width: 0, height: 6 } }}
        >
          <Calendar size={36} color="#6B8068" strokeWidth={1.5} />
        </View>
        <Text className="font-display-italic text-[26px] text-ink dark:text-cream text-center">
          Prenotazione in arrivo
        </Text>
        <Text className="font-body-medium text-[14px] text-ink-muted text-center mt-2 leading-relaxed">
          Calendar picker, slot grid, scelta tipo (con avversario, matchmaking, sparapalline).{'\n'}
          Stiamo costruendo questa schermata.
        </Text>
        <Text className="font-body-medium text-[12px] text-ink-muted text-center mt-4">
          Per ora prenota via WhatsApp scrivendo al bot.
        </Text>
      </View>
    </SafeAreaView>
  );
}
