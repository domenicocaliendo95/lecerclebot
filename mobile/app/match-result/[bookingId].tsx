import React, { useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { router, useLocalSearchParams } from 'expo-router';
import { LinearGradient } from 'expo-linear-gradient';
import { ChevronLeft, Trophy, XCircle, Frown } from 'lucide-react-native';

import { matchResults } from '@/lib/api';

type Outcome = 'won' | 'lost' | 'not_played';

export default function SubmitResult() {
  const { bookingId, opponent } = useLocalSearchParams<{ bookingId: string; opponent?: string }>();
  const [outcome, setOutcome] = useState<Outcome | null>(null);
  const [setsMe, setSetsMe] = useState('');
  const [setsOpp, setSetsOpp] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const score = setsMe && setsOpp ? `${setsMe}-${setsOpp}` : '';

  const submit = async () => {
    if (!outcome) return;
    setSubmitting(true);
    try {
      const res = await matchResults.submit(Number(bookingId), {
        outcome,
        score: outcome === 'not_played' ? undefined : (score || undefined),
      });
      if (res.warning === 'discordance') {
        Alert.alert(
          'Punteggio non coincide',
          `${opponent ?? "L'avversario"} ha registrato un risultato diverso. Un amministratore controllerà.`,
          [{ text: 'OK', onPress: () => router.back() }],
        );
      } else if (res.data.finalized && res.data.elo_delta != null) {
        const sign = res.data.elo_delta > 0 ? '+' : '';
        Alert.alert(
          'Risultato registrato!',
          `ELO: ${sign}${res.data.elo_delta}`,
          [{ text: 'OK', onPress: () => router.back() }],
        );
      } else {
        Alert.alert('Registrato!', "Ti faremo sapere quando l'avversario conferma.",
          [{ text: 'OK', onPress: () => router.back() }]);
      }
    } catch {
      Alert.alert('Errore', 'Salvataggio fallito. Riprova.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <SafeAreaView className="flex-1 bg-cream-light dark:bg-dark-bg" edges={['top']}>
      <View className="flex-row items-center justify-between px-5 pt-3 pb-2">
        <Pressable onPress={() => router.back()} className="w-10 h-10 items-center justify-center -ml-1">
          <ChevronLeft size={26} color="#1F2419" strokeWidth={1.5} />
        </Pressable>
        <Text className="font-display-italic text-[18px] text-ink dark:text-cream">Risultato</Text>
        <View className="w-10" />
      </View>

      <ScrollView contentContainerStyle={{ paddingBottom: 40 }}>
        <View className="px-6 pt-4 pb-6">
          <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted">Com'è andata?</Text>
          <Text className="font-display-italic text-[26px] text-ink dark:text-cream mt-1">
            {opponent ? `Tu vs ${opponent}` : 'La tua partita'}
          </Text>
        </View>

        {/* Outcome cards */}
        <View className="px-6 gap-3">
          <OutcomeCard
            icon={<Trophy size={28} color="#5C8A5E" strokeWidth={1.5} />}
            title="Ho vinto"
            subtitle="Ho portato a casa la partita"
            selected={outcome === 'won'}
            onPress={() => setOutcome('won')}
            tone="success"
          />
          <OutcomeCard
            icon={<Frown size={28} color="#3A4036" strokeWidth={1.5} />}
            title="Ho perso"
            subtitle="Vince l'avversario, vinco la prossima"
            selected={outcome === 'lost'}
            onPress={() => setOutcome('lost')}
            tone="neutral"
          />
          <OutcomeCard
            icon={<XCircle size={28} color="#A85B4F" strokeWidth={1.5} />}
            title="Non giocata"
            subtitle="Partita saltata, nessun risultato"
            selected={outcome === 'not_played'}
            onPress={() => setOutcome('not_played')}
            tone="danger"
          />
        </View>

        {/* Score (only if won/lost) */}
        {(outcome === 'won' || outcome === 'lost') && (
          <View className="px-6 mt-6">
            <Text className="font-body-bold text-[10px] tracking-widest uppercase text-ink-muted mb-2">
              Set conquistati (opzionale)
            </Text>
            <View className="flex-row items-center gap-3">
              <View className="flex-1 bg-white dark:bg-dark-surface rounded-2xl px-4 py-3">
                <Text className="font-body-bold text-[10px] text-ink-muted">TUOI SET</Text>
                <TextInput
                  value={setsMe}
                  onChangeText={(v) => setSetsMe(v.replace(/\D/g, '').slice(0, 1))}
                  placeholder="2"
                  placeholderTextColor="#7A7E72"
                  keyboardType="number-pad"
                  className="font-display-semi text-[28px] text-ink dark:text-cream mt-1"
                />
              </View>
              <Text className="font-display text-[24px] text-ink-muted">–</Text>
              <View className="flex-1 bg-white dark:bg-dark-surface rounded-2xl px-4 py-3">
                <Text className="font-body-bold text-[10px] text-ink-muted">SET AVV.</Text>
                <TextInput
                  value={setsOpp}
                  onChangeText={(v) => setSetsOpp(v.replace(/\D/g, '').slice(0, 1))}
                  placeholder="1"
                  placeholderTextColor="#7A7E72"
                  keyboardType="number-pad"
                  className="font-display-semi text-[28px] text-ink dark:text-cream mt-1"
                />
              </View>
            </View>
            <Text className="font-body-medium text-[11px] text-ink-muted mt-2">
              Lo score serve solo per memoria, l'ELO è calcolato sul vincitore.
            </Text>
          </View>
        )}

        {/* CTA */}
        <View className="px-6 mt-8">
          <Pressable
            disabled={!outcome || submitting}
            onPress={submit}
            className={`w-full py-[17px] rounded-full items-center ${outcome ? 'bg-sage' : 'bg-sage/40'}`}
            style={{
              shadowColor: '#6B8068',
              shadowOpacity: outcome ? 0.32 : 0,
              shadowRadius: 20,
              shadowOffset: { width: 0, height: 6 },
            }}
          >
            {submitting ? (
              <ActivityIndicator color="#ECE3CE" />
            ) : (
              <Text className="font-body-bold text-[15px] text-cream tracking-wide">Conferma risultato</Text>
            )}
          </Pressable>
          <Text className="font-body-medium text-[11px] text-ink-muted text-center mt-3 leading-relaxed">
            Per partite tracciate, ELO aggiornato quando entrambi confermano.{'\n'}
            Se i risultati non coincidono, un admin verifica.
          </Text>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

function OutcomeCard({
  icon, title, subtitle, selected, onPress, tone,
}: {
  icon: React.ReactNode; title: string; subtitle: string; selected: boolean; onPress: () => void;
  tone: 'success' | 'neutral' | 'danger';
}) {
  if (selected) {
    const colors = tone === 'success' ? ['#5C8A5E', '#7BAB7D']
                : tone === 'danger'  ? ['#A85B4F', '#C77367']
                : ['#3A4036', '#5A6056'];
    return (
      <Pressable onPress={onPress}>
        <LinearGradient
          colors={colors as any}
          start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }}
          style={{ borderRadius: 24, padding: 18, flexDirection: 'row', alignItems: 'center', gap: 14, shadowColor: colors[0], shadowOpacity: 0.32, shadowRadius: 20, shadowOffset: { width: 0, height: 6 } }}
        >
          <View className="w-12 h-12 rounded-full bg-cream/20 items-center justify-center">
            {React.cloneElement(icon as any, { color: '#ECE3CE' })}
          </View>
          <View className="flex-1">
            <Text className="font-display-semi text-[18px] text-cream">{title}</Text>
            <Text className="font-body-medium text-[12px] text-cream/85 mt-0.5">{subtitle}</Text>
          </View>
        </LinearGradient>
      </Pressable>
    );
  }

  return (
    <Pressable
      onPress={onPress}
      className="flex-row items-center gap-3 bg-white dark:bg-dark-surface rounded-3xl p-4"
      style={{ shadowColor: '#6B8068', shadowOpacity: 0.06, shadowRadius: 10, shadowOffset: { width: 0, height: 2 } }}
    >
      <View className="w-12 h-12 rounded-full bg-cream items-center justify-center">
        {icon}
      </View>
      <View className="flex-1">
        <Text className="font-display-semi text-[17px] text-ink dark:text-cream">{title}</Text>
        <Text className="font-body-medium text-[12px] text-ink-muted mt-0.5">{subtitle}</Text>
      </View>
    </Pressable>
  );
}

