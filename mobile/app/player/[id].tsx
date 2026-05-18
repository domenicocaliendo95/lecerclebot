import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router, useLocalSearchParams } from 'expo-router';
import { LinearGradient } from 'expo-linear-gradient';
import { ChevronLeft, MessageCircle, Trophy, UserPlus } from 'lucide-react-native';

import { PublicPlayer, RecentMatch, playersProfile } from '@/lib/api';
import { dateShort } from '@/lib/format';
import { Avatar } from '@/components/Avatar';

export default function PlayerProfile() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const playerId = Number(id);

  const [player, setPlayer] = useState<PublicPlayer | null>(null);
  const [recent, setRecent] = useState<RecentMatch[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    try {
      const [p, r] = await Promise.all([
        playersProfile.show(playerId),
        playersProfile.recentMatches(playerId).catch(() => []),
      ]);
      setPlayer(p);
      setRecent(r);
    } catch (err: any) {
      const code = err?.response?.data?.error?.code;
      if (code === 'private') {
        Alert.alert('Profilo privato', 'Questo giocatore ha scelto di non condividere il profilo.');
      } else {
        Alert.alert('Errore', 'Giocatore non trovato.');
      }
      router.back();
    } finally {
      setLoading(false);
    }
  }, [playerId]);

  useEffect(() => { void load(); }, [load]);

  if (loading || !player) {
    return (
      <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg items-center justify-center">
        <ActivityIndicator color="#6B8068" />
      </SafeAreaView>
    );
  }

  const matches = player.matches_played ?? 0;
  const wins = player.matches_won ?? 0;
  const winRate = matches > 0 ? Math.round((wins / matches) * 100) : 0;
  const h2h = player.head_to_head;

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <View className="flex-row items-center justify-between px-5 pt-3 pb-2">
        <Pressable onPress={() => router.back()} className="w-10 h-10 items-center justify-center -ml-1">
          <ChevronLeft size={26} color="#1F2419" strokeWidth={1.5} />
        </Pressable>
        <Text className="font-display-italic text-[18px] text-ink dark:text-cream">Profilo</Text>
        <View className="w-10" />
      </View>

      <ScrollView contentContainerStyle={{ paddingBottom: 40 }}>
        {/* Hero */}
        <View className="items-center px-6 pb-6">
          <Avatar url={player.avatar_url} name={player.name} size={104} bordered />
          <Text className="font-display-semi text-[28px] text-ink dark:text-cream mt-4">{player.name}</Text>
          {player.bio && (
            <Text className="font-display-italic text-[13px] text-ink-muted mt-1 text-center">"{player.bio}"</Text>
          )}

          <View className="flex-row gap-2 mt-3">
            {player.fit_rating && <Chip label={`FIT ${player.fit_rating}`} tone="sage" />}
            {player.self_level && <Chip label={['Neofita', 'Dilettante', 'Avanzato'][player.self_level - 1] ?? 'Livello'} tone="ocra" />}
          </View>
        </View>

        {/* Action buttons (solo se non è me) */}
        {!player.is_me && (
          <View className="px-6 flex-row gap-2 mb-5">
            <Pressable
              onPress={() => Alert.alert('Amici', 'Funzione in arrivo.')}
              className="flex-1 bg-white dark:bg-dark-surface rounded-full py-3 flex-row items-center justify-center gap-2"
              style={{ shadowColor: '#6B8068', shadowOpacity: 0.08, shadowRadius: 10, shadowOffset: { width: 0, height: 2 } }}
            >
              <UserPlus size={16} color="#4F6450" strokeWidth={1.5} />
              <Text className="font-body-semi text-[13px] text-ink dark:text-cream">Aggiungi</Text>
            </Pressable>
            <Pressable
              onPress={() => Alert.alert('Chat', 'Funzione in arrivo.')}
              className="flex-1 bg-sage rounded-full py-3 flex-row items-center justify-center gap-2"
              style={{ shadowColor: '#6B8068', shadowOpacity: 0.28, shadowRadius: 12, shadowOffset: { width: 0, height: 4 } }}
            >
              <MessageCircle size={16} color="#ECE3CE" strokeWidth={1.5} />
              <Text className="font-body-bold text-[13px] text-cream">Messaggio</Text>
            </Pressable>
          </View>
        )}

        {/* Stats card */}
        <View className="px-6">
          <View
            className="bg-white dark:bg-dark-surface rounded-3xl p-5"
            style={{ shadowColor: '#6B8068', shadowOpacity: 0.08, shadowRadius: 16, shadowOffset: { width: 0, height: 4 } }}
          >
            <View className="flex-row items-center">
              <Stat label="ELO" value={String(player.elo_rating ?? '—')} sub="" />
              <Divider />
              <Stat label="Partite" value={String(matches)} sub={`${wins}W · ${matches - wins}L`} />
              <Divider />
              <Stat label="Win rate" value={`${winRate}%`} sub="Storico" />
            </View>
          </View>
        </View>

        {/* Head to head */}
        {!player.is_me && h2h.played > 0 && (
          <View className="px-6 mt-5">
            <Text className="font-display-italic text-[18px] text-ink dark:text-cream mb-3">Testa a testa</Text>
            <LinearGradient
              colors={['#6B8068', '#8AA086']}
              start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}
              style={{ borderRadius: 24, padding: 18, shadowColor: '#6B8068', shadowOpacity: 0.28, shadowRadius: 16, shadowOffset: { width: 0, height: 4 } }}
            >
              <Text className="font-body-bold text-[10px] tracking-widest uppercase text-cream/85">
                {h2h.played} {h2h.played === 1 ? 'partita giocata' : 'partite giocate'}
              </Text>
              <View className="flex-row items-center mt-3">
                <View className="flex-1 items-center">
                  <Text className="font-display-bold text-[40px] text-cream leading-none">{h2h.me_wins}</Text>
                  <Text className="font-body-bold text-[10px] tracking-widest uppercase text-cream/75 mt-1">Tue</Text>
                </View>
                <Text className="font-display-italic text-[18px] text-cream/60">vs</Text>
                <View className="flex-1 items-center">
                  <Text className="font-display-bold text-[40px] text-cream leading-none">{h2h.other_wins}</Text>
                  <Text className="font-body-bold text-[10px] tracking-widest uppercase text-cream/75 mt-1">Sue</Text>
                </View>
              </View>
            </LinearGradient>
          </View>
        )}

        {/* Recent matches */}
        {recent.length > 0 && (
          <View className="px-6 mt-6">
            <Text className="font-display-italic text-[18px] text-ink dark:text-cream mb-3">
              Ultime partite
            </Text>
            <View className="bg-white dark:bg-dark-surface rounded-3xl overflow-hidden">
              {recent.map((m, i) => (
                <View key={i}>
                  {i > 0 && <View className="h-px bg-divider mx-5" style={{ backgroundColor: 'rgba(31,36,25,0.06)' }} />}
                  <View className="flex-row items-center gap-3 px-5 py-4">
                    <View className={`w-10 h-10 rounded-full items-center justify-center ${m.won ? 'bg-success/15' : 'bg-divider'}`}>
                      <Trophy size={18} color={m.won ? '#5C8A5E' : '#7A7E72'} strokeWidth={1.5} />
                    </View>
                    <View className="flex-1">
                      <Text className="font-body-semi text-[14px] text-ink dark:text-cream">
                        {m.won ? 'Vinta' : 'Persa'} vs {m.opponent_name ?? 'avversario'}
                      </Text>
                      <Text className="font-body-medium text-[11px] text-ink-muted mt-0.5">
                        {dateShort(m.date)}{m.score ? ` · ${m.score}` : ''}
                      </Text>
                    </View>
                    {m.elo_delta != null && (
                      <Text className={`font-display-semi text-[15px] ${m.elo_delta > 0 ? 'text-success' : 'text-ink-muted'}`}>
                        {m.elo_delta > 0 ? '+' : ''}{m.elo_delta}
                      </Text>
                    )}
                  </View>
                </View>
              ))}
            </View>
          </View>
        )}

        {recent.length === 0 && (
          <View className="px-6 mt-6 items-center">
            <Text className="font-body-medium text-[13px] text-ink-muted text-center">
              Nessuna partita giocata ancora.
            </Text>
          </View>
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

function Stat({ label, value, sub }: { label: string; value: string; sub: string }) {
  return (
    <View className="flex-1 items-center">
      <Text className="font-display-semi text-[26px] text-ink dark:text-cream">{value}</Text>
      <Text className="font-body-bold text-[9px] tracking-widest uppercase text-ink-muted mt-1">{label}</Text>
      {sub ? <Text className="font-body-medium text-[10px] text-ink-muted mt-0.5">{sub}</Text> : null}
    </View>
  );
}

function Chip({ label, tone }: { label: string; tone: 'sage' | 'ocra' }) {
  const bg = tone === 'sage' ? 'bg-sage/12' : 'bg-ocra/18';
  const txt = tone === 'sage' ? 'text-sage-dark' : 'text-ocra-dark';
  return (
    <View className={`${bg} px-3 py-1 rounded-full`}>
      <Text className={`font-body-bold text-[11px] tracking-wide ${txt}`}>{label}</Text>
    </View>
  );
}

function Divider() {
  return <View style={{ width: 1, height: 36, backgroundColor: 'rgba(31,36,25,0.08)', marginHorizontal: 8 }} />;
}
