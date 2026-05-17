import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { LinearGradient } from 'expo-linear-gradient';
import { router, useLocalSearchParams } from 'expo-router';
import { ChevronLeft, CreditCard, MapPin, MessageCircle, Trash2, Trophy } from 'lucide-react-native';
import Svg, { Circle } from 'react-native-svg';

import { AppBooking, bookings } from '@/lib/api';
import { dateFull, dateRelative, money, timeUntil } from '@/lib/format';
import { Avatar } from '@/components/Avatar';

export default function BookingDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [booking, setBooking] = useState<AppBooking | null>(null);
  const [loading, setLoading] = useState(true);
  const [cancelling, setCancelling] = useState(false);

  const load = useCallback(async () => {
    try {
      const b = await bookings.show(Number(id));
      setBooking(b);
    } catch {
      Alert.alert('Errore', 'Prenotazione non trovata.');
      router.back();
    } finally {
      setLoading(false);
    }
  }, [id]);

  useEffect(() => { void load(); }, [load]);

  const handleCancel = () => {
    Alert.alert(
      'Cancellare la prenotazione?',
      'Lo slot verrà liberato. Operazione non reversibile.',
      [
        { text: 'No', style: 'cancel' },
        {
          text: 'Sì, cancella',
          style: 'destructive',
          onPress: async () => {
            if (!booking) return;
            setCancelling(true);
            try {
              await bookings.cancel(booking.id);
              Alert.alert('Cancellata', 'La prenotazione è stata cancellata.');
              router.back();
            } catch {
              Alert.alert('Errore', 'Impossibile cancellare. Riprova.');
            } finally {
              setCancelling(false);
            }
          },
        },
      ],
    );
  };

  if (loading || !booking) {
    return (
      <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg items-center justify-center">
        <ActivityIndicator color="#6B8068" />
      </SafeAreaView>
    );
  }

  const opponentName = booking.opponent?.name ?? booking.opponent_name_text ?? 'Avversario libero';
  const startsAt = new Date(booking.starts_at_iso);
  const time = timeUntil(startsAt);
  const isPast = startsAt < new Date();
  const isCancelled = booking.status === 'cancelled';
  const canCancel = !isPast && !isCancelled;

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <View className="flex-row items-center justify-between px-5 pt-3 pb-2">
        <Pressable onPress={() => router.back()} className="w-10 h-10 items-center justify-center -ml-1">
          <ChevronLeft size={26} color="#1F2419" strokeWidth={1.5} />
        </Pressable>
        <Text className="font-display-italic text-[18px] text-ink dark:text-cream">Prenotazione</Text>
        <View className="w-10" />
      </View>

      <ScrollView contentContainerStyle={{ paddingBottom: 40 }}>
        {/* Hero card */}
        <View className="px-5 mt-3">
          <LinearGradient
            colors={isCancelled ? ['#A85B4F', '#8E4A3F'] : ['#6B8068', '#8AA086']}
            start={{ x: 0, y: 0 }}
            end={{ x: 1, y: 1 }}
            style={{
              borderRadius: 32, padding: 24,
              shadowColor: '#6B8068', shadowOpacity: 0.32, shadowRadius: 28, shadowOffset: { width: 0, height: 14 },
            }}
          >
            <View style={{ position: 'absolute', right: -50, top: -50, opacity: 0.18 }}>
              <Svg width={200} height={200} viewBox="0 0 200 200">
                <Circle cx="100" cy="100" r="85" stroke="#ECE3CE" strokeWidth="2" fill="none" />
                <Circle cx="100" cy="100" r="55" stroke="#ECE3CE" strokeWidth="1.5" fill="none" />
                <Circle cx="100" cy="100" r="25" stroke="#ECE3CE" strokeWidth="1" fill="none" />
              </Svg>
            </View>

            <Text className="font-body-bold text-[10px] tracking-widest uppercase text-cream/80">
              {dateRelative(booking.date)} · {dateFull(booking.date)}
            </Text>
            <Text className="font-display text-[36px] text-cream leading-[40px] mt-2">
              <Text className="italic">{booking.start_time}</Text> — {booking.end_time}
            </Text>
            <Text className="font-body-medium text-[13px] text-cream/85 mt-1.5">
              {booking.duration_minutes} min · {money(booking.price)}{booking.is_peak ? ' · peak' : ''}
            </Text>
            {time && !isPast && !isCancelled && (
              <View className="mt-3 self-start bg-ocra px-3 py-1 rounded-full">
                <Text className="font-body-bold text-[10px] text-white tracking-wide">
                  {time.toUpperCase()}
                </Text>
              </View>
            )}
            {isCancelled && (
              <View className="mt-3 self-start bg-cream/30 px-3 py-1 rounded-full">
                <Text className="font-body-bold text-[10px] text-cream tracking-wide">CANCELLATA</Text>
              </View>
            )}
            {booking.status === 'pending_match' && (
              <View className="mt-3 self-start bg-cream/30 px-3 py-1 rounded-full">
                <Text className="font-body-bold text-[10px] text-cream tracking-wide">IN ATTESA AVVERSARIO</Text>
              </View>
            )}
          </LinearGradient>
        </View>

        {/* Opponent */}
        <View className="px-5 mt-4">
          <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted mb-2">Avversario</Text>
          <View className="bg-white dark:bg-dark-surface rounded-3xl p-4 flex-row items-center gap-3" style={{ shadowColor: '#6B8068', shadowOpacity: 0.08, shadowRadius: 14, shadowOffset: { width: 0, height: 3 } }}>
            <Avatar url={booking.opponent?.avatar_url} name={opponentName} size={56} bordered />
            <View className="flex-1">
              <Text className="font-display-semi text-[18px] text-ink dark:text-cream">{opponentName}</Text>
              {booking.opponent?.elo_rating != null && (
                <Text className="font-body-medium text-[12px] text-ink-muted">ELO {booking.opponent.elo_rating}</Text>
              )}
              {!booking.opponent && booking.opponent_name_text && (
                <Text className="font-body-medium text-[12px] text-ink-muted">Esterno · niente ELO</Text>
              )}
              {booking.opponent && !booking.opponent_confirmed && (
                <Text className="font-body-medium text-[12px] text-ocra-dark mt-0.5">In attesa di conferma</Text>
              )}
            </View>
            {booking.opponent && (
              <Pressable className="w-10 h-10 rounded-full bg-cream items-center justify-center">
                <MessageCircle size={20} color="#4F6450" strokeWidth={1.5} />
              </Pressable>
            )}
          </View>
        </View>

        {/* Meta */}
        <View className="px-5 mt-4">
          <View className="bg-white dark:bg-dark-surface rounded-3xl p-4 gap-3">
            <Row icon={<MapPin size={18} color="#7A7E72" strokeWidth={1.5} />} label="Le Cercle Tennis Club" sub="San Gennaro Vesuviano (NA)" />
            <View className="h-px bg-divider" />
            <Row icon={<CreditCard size={18} color="#7A7E72" strokeWidth={1.5} />} label={`${money(booking.price)} ${booking.is_peak ? '· orario peak' : ''}`} sub="Pagamento in loco" />
          </View>
        </View>

        {/* Register result CTA (past + has opponent + not cancelled) */}
        {isPast && !isCancelled && (booking.opponent || booking.opponent_name_text) && (
          <View className="px-5 mt-5">
            <Pressable
              onPress={() => router.push({
                pathname: `/match-result/${booking.id}`,
                params: { opponent: opponentName },
              })}
              className="bg-sage rounded-3xl p-4 flex-row items-center gap-3"
              style={{ shadowColor: '#6B8068', shadowOpacity: 0.32, shadowRadius: 16, shadowOffset: { width: 0, height: 6 } }}
            >
              <View className="w-10 h-10 rounded-full bg-cream/20 items-center justify-center">
                <Trophy size={20} color="#ECE3CE" strokeWidth={1.5} />
              </View>
              <View className="flex-1">
                <Text className="font-display-semi text-[15px] text-cream">Inserisci risultato</Text>
                <Text className="font-body-medium text-[12px] text-cream/85 mt-0.5">
                  Aggiorna ELO e statistiche
                </Text>
              </View>
            </Pressable>
          </View>
        )}

        {/* Cancel */}
        {canCancel && (
          <View className="px-5 mt-5">
            <Pressable
              onPress={handleCancel}
              disabled={cancelling}
              className="bg-white dark:bg-dark-surface rounded-3xl p-4 flex-row items-center gap-3"
              style={{ shadowColor: '#A85B4F', shadowOpacity: 0.08, shadowRadius: 12, shadowOffset: { width: 0, height: 3 } }}
            >
              <Trash2 size={20} color="#A85B4F" strokeWidth={1.5} />
              <Text className="flex-1 font-body-bold text-[15px] text-danger">
                {cancelling ? 'Cancellazione...' : 'Cancella prenotazione'}
              </Text>
            </Pressable>
          </View>
        )}

        {booking.notes && (
          <View className="px-5 mt-5">
            <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted mb-2">Note</Text>
            <View className="bg-white dark:bg-dark-surface rounded-3xl p-4">
              <Text className="text-[14px] text-ink dark:text-cream font-body-medium leading-relaxed">{booking.notes}</Text>
            </View>
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function Row({ icon, label, sub }: { icon: React.ReactNode; label: string; sub?: string }) {
  return (
    <View className="flex-row items-center gap-3">
      <View className="w-9 h-9 rounded-full bg-cream items-center justify-center">{icon}</View>
      <View className="flex-1">
        <Text className="font-body-semi text-[14px] text-ink dark:text-cream">{label}</Text>
        {sub ? <Text className="font-body-medium text-[12px] text-ink-muted mt-0.5">{sub}</Text> : null}
      </View>
    </View>
  );
}
