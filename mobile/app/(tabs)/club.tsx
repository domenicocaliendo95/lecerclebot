import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useFocusEffect } from 'expo-router';
import { Trophy, Wine } from 'lucide-react-native';

import { LeaderboardEntry, leaderboard } from '@/lib/api';
import { Avatar } from '@/components/Avatar';

export default function Club() {
  const [tab, setTab] = useState<'leaderboard' | 'tornei' | 'eventi'>('leaderboard');
  const [data, setData] = useState<{ entries: LeaderboardEntry[]; me: any } | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await leaderboard.get();
      setData({ entries: res.data, me: res.me });
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);
  useFocusEffect(useCallback(() => { void load(); }, [load]));

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <View className="px-6 pt-3 pb-3">
        <Text className="font-display-italic text-[26px] text-ink dark:text-cream">Il circolo</Text>
        <Text className="font-body-medium text-[13px] text-ink-muted mt-1">
          Classifica, tornei, eventi di Le Cercle
        </Text>
      </View>

      <View className="flex-row gap-2 px-6 pb-3">
        <Tab label="Classifica" active={tab === 'leaderboard'} onPress={() => setTab('leaderboard')} />
        <Tab label="Tornei"     active={tab === 'tornei'}      onPress={() => setTab('tornei')} />
        <Tab label="Eventi"     active={tab === 'eventi'}      onPress={() => setTab('eventi')} />
      </View>

      {tab === 'leaderboard' && (
        loading ? (
          <View className="flex-1 items-center justify-center">
            <ActivityIndicator color="#6B8068" />
          </View>
        ) : (
          <FlatList
            data={data?.entries ?? []}
            keyExtractor={(e) => String(e.id)}
            contentContainerStyle={{ paddingHorizontal: 20, paddingBottom: 100 }}
            renderItem={({ item }) => <LeaderRow e={item} />}
            ItemSeparatorComponent={() => <View className="h-2" />}
            refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }} tintColor="#6B8068" />}
            ListEmptyComponent={
              <Text className="text-center text-ink-muted mt-20">Nessun giocatore.</Text>
            }
            ListFooterComponent={
              data?.me ? (
                <View className="mt-4 bg-sage rounded-2xl px-4 py-3 flex-row items-center" style={{ shadowColor: '#6B8068', shadowOpacity: 0.32, shadowRadius: 16, shadowOffset: { width: 0, height: 4 } }}>
                  <Text className="font-display-bold text-[18px] text-cream w-10">#{data.me.rank}</Text>
                  <Text className="font-body-bold text-[14px] text-cream flex-1">La tua posizione</Text>
                  <Text className="font-display-semi text-[20px] text-cream">{data.me.elo_rating ?? '—'}</Text>
                </View>
              ) : null
            }
          />
        )
      )}

      {tab === 'tornei' && (
        <ComingSoon
          icon={<Trophy size={32} color="#6B8068" strokeWidth={1.5} />}
          title="Tornei in arrivo"
          subtitle="Iscrizioni, bracket, classifiche.\nStiamo costruendo."
        />
      )}

      {tab === 'eventi' && (
        <ComingSoon
          icon={<Wine size={32} color="#A47A3F" strokeWidth={1.5} />}
          title="Eventi in arrivo"
          subtitle="Aperitivi, cene, clinic.\nStiamo costruendo."
        />
      )}
    </SafeAreaView>
  );
}

function Tab({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable
      onPress={onPress}
      className={`px-4 py-2 rounded-full ${active ? 'bg-sage' : 'bg-white dark:bg-dark-surface'}`}
      style={active ? { shadowColor: '#6B8068', shadowOpacity: 0.28, shadowRadius: 12, shadowOffset: { width: 0, height: 4 } } : { shadowColor: '#6B8068', shadowOpacity: 0.06, shadowRadius: 6, shadowOffset: { width: 0, height: 2 } }}
    >
      <Text className={`font-body-semi text-[12px] ${active ? 'text-cream' : 'text-ink dark:text-cream'}`}>
        {label}
      </Text>
    </Pressable>
  );
}

function LeaderRow({ e }: { e: LeaderboardEntry }) {
  const medal = e.rank === 1 ? '🥇' : e.rank === 2 ? '🥈' : e.rank === 3 ? '🥉' : null;
  const winRate = e.matches_played > 0 ? Math.round((e.matches_won / e.matches_played) * 100) : 0;

  return (
    <View
      className={`flex-row items-center gap-3 px-3 py-3 rounded-2xl ${e.is_me ? 'bg-cream' : 'bg-white dark:bg-dark-surface'}`}
      style={{ shadowColor: '#6B8068', shadowOpacity: e.is_me ? 0.18 : 0.06, shadowRadius: 10, shadowOffset: { width: 0, height: 2 } }}
    >
      <View className="w-9 items-center">
        {medal ? (
          <Text className="text-[22px]">{medal}</Text>
        ) : (
          <Text className="font-display-semi text-[16px] text-ink-muted">#{e.rank}</Text>
        )}
      </View>
      <Avatar url={e.avatar_url} name={e.name} size={36} />
      <View className="flex-1">
        <Text className="font-display-semi text-[15px] text-ink dark:text-cream" numberOfLines={1}>
          {e.name}{e.is_me ? ' · tu' : ''}
        </Text>
        <Text className="font-body-medium text-[11px] text-ink-muted mt-0.5">
          {e.matches_played} partite · {winRate}% wr{e.fit_rating ? ` · FIT ${e.fit_rating}` : ''}
        </Text>
      </View>
      <Text className="font-display-bold text-[18px] text-ink dark:text-cream">{e.elo_rating}</Text>
    </View>
  );
}

function ComingSoon({ icon, title, subtitle }: { icon: React.ReactNode; title: string; subtitle: string }) {
  return (
    <View className="flex-1 items-center justify-center px-12">
      <View className="w-16 h-16 rounded-full bg-white dark:bg-dark-surface items-center justify-center mb-4" style={{ shadowColor: '#6B8068', shadowOpacity: 0.1, shadowRadius: 16, shadowOffset: { width: 0, height: 4 } }}>
        {icon}
      </View>
      <Text className="font-display-italic text-[22px] text-ink dark:text-cream text-center">{title}</Text>
      <Text className="font-body-medium text-[13px] text-ink-muted text-center mt-2 leading-relaxed">{subtitle}</Text>
    </View>
  );
}
