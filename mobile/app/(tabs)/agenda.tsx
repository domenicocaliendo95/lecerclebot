import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router, useFocusEffect } from 'expo-router';
import { Plus } from 'lucide-react-native';

import { AppBooking, bookings } from '@/lib/api';
import { dateFull, dateRelative, money } from '@/lib/format';
import { Avatar } from '@/components/Avatar';

type Tab = 'upcoming' | 'past';

export default function Agenda() {
  const [tab, setTab] = useState<Tab>('upcoming');
  const [items, setItems] = useState<AppBooking[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async (which: Tab) => {
    try {
      const res = await bookings.list({ status: which });
      setItems(res.data);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(tab); }, [tab, load]);
  useFocusEffect(useCallback(() => { void load(tab); }, [tab, load]));

  const onRefresh = async () => {
    setRefreshing(true);
    await load(tab);
    setRefreshing(false);
  };

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <View className="flex-row justify-between items-center px-6 pt-3 pb-2">
        <Text className="font-display-italic text-[26px] text-ink dark:text-cream">Le tue partite</Text>
        <Pressable
          onPress={() => router.push('/booking/new')}
          className="w-10 h-10 rounded-full bg-sage items-center justify-center"
          style={{ shadowColor: '#6B8068', shadowOpacity: 0.3, shadowRadius: 12, shadowOffset: { width: 0, height: 4 } }}
        >
          <Plus size={20} color="#ECE3CE" strokeWidth={2} />
        </Pressable>
      </View>

      <View className="flex-row gap-2 px-6 pt-2 pb-3">
        <TabPill label="In arrivo" active={tab === 'upcoming'} onPress={() => setTab('upcoming')} />
        <TabPill label="Passate"   active={tab === 'past'}     onPress={() => setTab('past')} />
      </View>

      {loading ? (
        <View className="flex-1 items-center justify-center">
          <ActivityIndicator color="#6B8068" />
        </View>
      ) : (
        <FlatList
          data={items}
          keyExtractor={(b) => String(b.id)}
          contentContainerStyle={{ paddingHorizontal: 20, paddingTop: 4, paddingBottom: 40 }}
          ItemSeparatorComponent={() => <View className="h-3" />}
          ListEmptyComponent={
            <View className="items-center mt-20 px-8">
              <Text className="font-display-italic text-[20px] text-ink dark:text-cream text-center">
                {tab === 'upcoming' ? 'Niente in agenda' : 'Niente da mostrare'}
              </Text>
              <Text className="font-body-medium text-[13px] text-ink-muted text-center mt-2 leading-relaxed">
                {tab === 'upcoming'
                  ? 'Tocca + per prenotare il prossimo campo'
                  : 'Le tue partite passate appariranno qui'}
              </Text>
            </View>
          }
          renderItem={({ item }) => <BookingRow b={item} />}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor="#6B8068" />}
        />
      )}
    </SafeAreaView>
  );
}

function TabPill({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      className={`px-5 py-2.5 rounded-full ${active ? 'bg-sage' : 'bg-white dark:bg-dark-surface'}`}
      style={
        active
          ? { shadowColor: '#6B8068', shadowOpacity: 0.28, shadowRadius: 12, shadowOffset: { width: 0, height: 4 } }
          : { shadowColor: '#6B8068', shadowOpacity: 0.06, shadowRadius: 6, shadowOffset: { width: 0, height: 2 } }
      }
    >
      <Text className={`font-body-semi text-[13px] ${active ? 'text-cream' : 'text-ink dark:text-cream'}`}>
        {label}
      </Text>
    </Pressable>
  );
}

function BookingRow({ b }: { b: AppBooking }) {
  const opponentName = b.opponent?.name ?? b.opponent_name_text ?? 'Avversario libero';
  const dateLabel = dateRelative(b.date) + ' · ' + dateFull(b.date);

  return (
    <Pressable
      onPress={() => router.push(`/booking/${b.id}`)}
      className="bg-white dark:bg-dark-surface rounded-3xl p-4 flex-row items-center gap-3"
      style={{ shadowColor: '#6B8068', shadowOpacity: 0.08, shadowRadius: 14, shadowOffset: { width: 0, height: 3 } }}
    >
      {/* Time block */}
      <View className="items-center justify-center w-14 py-2 rounded-2xl bg-cream">
        <Text className="font-display-semi text-[20px] text-sage-dark leading-none">{b.start_time}</Text>
        <Text className="font-body-bold text-[9px] tracking-widest uppercase text-ink-muted mt-1">
          {b.duration_minutes >= 90 ? `${b.duration_minutes}m` : '1h'}
        </Text>
      </View>

      {/* Body */}
      <View className="flex-1">
        <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted">{dateLabel}</Text>
        <View className="flex-row items-center gap-2 mt-1">
          <Avatar url={b.opponent?.avatar_url} name={opponentName} size={20} />
          <Text className="font-display-semi text-[15px] text-ink dark:text-cream flex-1" numberOfLines={1}>
            vs {opponentName}
          </Text>
        </View>
        <View className="flex-row items-center gap-2 mt-1">
          {b.status === 'pending_match' && (
            <View className="bg-ocra/20 px-2 py-0.5 rounded-full">
              <Text className="font-body-bold text-[9px] text-ocra-dark tracking-wide">IN ATTESA</Text>
            </View>
          )}
          {b.status === 'cancelled' && (
            <View className="bg-danger/15 px-2 py-0.5 rounded-full">
              <Text className="font-body-bold text-[9px] text-danger tracking-wide">CANCELLATA</Text>
            </View>
          )}
          <Text className="font-body-medium text-[11px] text-ink-muted">
            {money(b.price)} {b.is_peak && '· peak'}
          </Text>
        </View>
      </View>
    </Pressable>
  );
}
