import { Form } from '@inertiajs/react';
import { CircleCheck, CircleMinus, Plus, Trash2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import AgentController from '@/actions/App/Http/Controllers/ProjectAgentController';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type AgentConfigurationSkill = {
    id: number;
    name: string;
    position?: number;
};

export type AgentConfigurationAgent = {
    id: number;
    role: string;
    name: string;
    identity: string | null;
    harness: string;
    model: string | null;
    settings: Record<string, unknown> | null;
    default_context: string | null;
    workflow_instructions: string | null;
    enabled: boolean;
    skills: AgentConfigurationSkill[];
};

type ProjectSummary = {
    id: number;
    title: string;
};

type SettingRow = {
    id: string;
    key: string;
    value: string;
};

const AGENT_MODEL_OPTIONS: Record<string, { value: string; label: string }[]> =
    {
        codex: [
            { value: 'gpt-5.6', label: 'GPT-5.6 Sol' },
            { value: 'gpt-5.6-terra', label: 'GPT-5.6 Terra' },
            { value: 'gpt-5.6-luna', label: 'GPT-5.6 Luna' },
            { value: 'gpt-5.3-codex', label: 'GPT-5.3 Codex' },
        ],

        claude: [
            { value: 'claude-opus-5', label: 'Claude Opus 5' },
            { value: 'claude-sonnet-5', label: 'Claude Sonnet 5' },
            { value: 'claude-opus-4-8', label: 'Claude Opus 4.8' },
            { value: 'claude-sonnet-4-6', label: 'Claude Sonnet 4.6' },
            {
                value: 'claude-haiku-4-5-20251001',
                label: 'Claude Haiku 4.5',
            },
        ],
    };

const REASONING_OPTIONS = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
] as const;

type ConfigurationSectionProps = {
    title: string;
    description: string;
    children: React.ReactNode;
};

type TextFieldProps = {
    id: string;
    label: string;
    name: string;
    defaultValue: string;
    error?: string;
    placeholder?: string;
};

type TextareaFieldProps = TextFieldProps & {
    rows?: number;
};

type AgentConfigurationDialogProps = {
    project: ProjectSummary;
    agent: AgentConfigurationAgent;
    skills: AgentConfigurationSkill[];
    avatarSrc?: string;
    onClose: () => void;
};

/**
 * Convert persisted Agent settings into editable key-value rows.
 */
function createSettingRows(
    settings: Record<string, unknown> | null,
): SettingRow[] {
    return Object.entries(settings ?? {})
        .filter(([key]) => key !== 'reasoning')
        .map(([key, value], index) => ({
            id: `setting-${index}`,
            key,
            value: settingValueToInput(value),
        }));
}

/**
 * Serialize one persisted JSON value for display in the value input.
 */
function settingValueToInput(value: unknown): string {
    return JSON.stringify(value) ?? '';
}

/**
 * Parse a setting input as JSON when valid and otherwise retain it as text.
 */
function parseSettingValue(value: string): unknown {
    const normalizedValue = value.trim();

    if (normalizedValue === '') {
        return '';
    }

    try {
        return JSON.parse(normalizedValue);
    } catch {
        return value;
    }
}

/**
 * Serialize key-value rows back into the existing settings JSON contract.
 */
function serializeSettings(rows: SettingRow[], reasoning: string): string {
    if (rows.length === 0 && reasoning === '') {
        return '';
    }

    const settings = Object.fromEntries(
        rows.map((row) => [row.key.trim(), parseSettingValue(row.value)]),
    );

    if (reasoning !== '') {
        settings.reasoning = reasoning;
    }

    return JSON.stringify(settings);
}

/**
 * Return the selectable models for a harness while preserving legacy values.
 */
function modelOptionsFor(
    harness: string,
    currentModel: string | null,
): { value: string; label: string }[] {
    const options = AGENT_MODEL_OPTIONS[harness] ?? [];

    if (
        currentModel !== null &&
        currentModel !== '' &&
        !options.some((option) => option.value === currentModel)
    ) {
        return [
            { value: currentModel, label: `${currentModel} (current)` },
            ...options,
        ];
    }

    return options;
}

/**
 * Validate client-side key requirements before allowing submission.
 */
function validateSettingRows(rows: SettingRow[]): string | null {
    const keys = rows.map((row) => row.key.trim());

    if (keys.some((key) => key === '')) {
        return 'Setting keys cannot be blank.';
    }

    if (new Set(keys).size !== keys.length) {
        return 'Setting keys must be unique.';
    }

    return null;
}

/**
 * Narrow optional skill values after ID lookup.
 */
function isDefinedSkill(
    skill: AgentConfigurationSkill | undefined,
): skill is AgentConfigurationSkill {
    return skill !== undefined;
}

/**
 * Render a responsive configuration section with descriptive context.
 */
function ConfigurationSection({
    title,
    description,
    children,
}: ConfigurationSectionProps) {
    return (
        <section className="border-border grid gap-4 border-b px-5 py-5 last:border-b-0 md:grid-cols-[12rem_minmax(0,1fr)] md:gap-8 md:px-6">
            <div>
                <h3 className="text-sm font-semibold">{title}</h3>
                <p className="text-muted-foreground mt-1 text-sm leading-5">
                    {description}
                </p>
            </div>

            <div className="min-w-0">{children}</div>
        </section>
    );
}

/**
 * Render one labelled single-line Agent field with validation feedback.
 */
function TextField({
    id,
    label,
    name,
    defaultValue,
    error,
    placeholder,
}: TextFieldProps) {
    const errorId = `${id}-error`;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={name}
                defaultValue={defaultValue}
                placeholder={placeholder}
                aria-invalid={Boolean(error)}
                aria-describedby={error ? errorId : undefined}
            />
            <InputError id={errorId} message={error} />
        </div>
    );
}

/**
 * Render one labelled multi-line Agent field with validation feedback.
 */
function TextareaField({
    id,
    label,
    name,
    defaultValue,
    error,
    placeholder,
    rows = 4,
}: TextareaFieldProps) {
    const errorId = `${id}-error`;

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <textarea
                id={id}
                name={name}
                defaultValue={defaultValue}
                placeholder={placeholder}
                rows={rows}
                aria-invalid={Boolean(error)}
                aria-describedby={error ? errorId : undefined}
                className="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 min-h-24 w-full resize-y rounded-md border px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px]"
            />
            <InputError id={errorId} message={error} />
        </div>
    );
}

/**
 * Render and manage the complete Agent configuration modal.
 */
export default function AgentConfigurationDialog({
    project,
    agent,
    skills,
    avatarSrc,
    onClose,
}: AgentConfigurationDialogProps) {
    const initialSettingRows = createSettingRows(agent.settings);
    const nextSettingId = useRef(initialSettingRows.length);
    const [enabled, setEnabled] = useState(agent.enabled);
    const [harness, setHarness] = useState(agent.harness);
    const [model, setModel] = useState(agent.model ?? '');
    const [reasoning, setReasoning] = useState(
        typeof agent.settings?.reasoning === 'string'
            ? agent.settings.reasoning
            : '',
    );
    const [settingRows, setSettingRows] =
        useState<SettingRow[]>(initialSettingRows);
    const [selectedSkillIds, setSelectedSkillIds] = useState<number[]>(
        agent.skills.map((skill) => skill.id),
    );
    const [skillToAdd, setSkillToAdd] = useState('');

    const settingsClientError = validateSettingRows(settingRows);
    const serializedSettings = serializeSettings(settingRows, reasoning);
    const modelOptions = modelOptionsFor(
        harness,
        harness === agent.harness ? agent.model : null,
    );
    const availableSkills = skills.filter(
        (skill) => !selectedSkillIds.includes(skill.id),
    );
    const selectedSkills = selectedSkillIds
        .map((skillId) => skills.find((skill) => skill.id === skillId))
        .filter(isDefinedSkill);
    const fieldPrefix = `agent-${agent.id}`;

    /**
     * Close the modal when Radix reports an explicit close action.
     */
    function handleDialogOpenChange(open: boolean): void {
        if (!open) {
            onClose();
        }
    }

    /**
     * Update the persisted enabled state selection.
     */
    function handleEnabledChange(value: string): void {
        setEnabled(value === '1');
    }

    /**
     * Switch the provider harness and reset the model to its provider default.
     */
    function handleHarnessChange(value: string): void {
        setHarness(value);
        setModel('');
    }

    /**
     * Update one key or value inside the Advanced Settings editor.
     */
    function handleSettingChange(
        id: string,
        field: 'key' | 'value',
        value: string,
    ): void {
        setSettingRows((rows) =>
            rows.map((row) =>
                row.id === id ? { ...row, [field]: value } : row,
            ),
        );
    }

    /**
     * Append a new empty setting row for user configuration.
     */
    function handleAddSetting(): void {
        setSettingRows((rows) => [
            ...rows,
            {
                id: `setting-new-${nextSettingId.current++}`,
                key: '',
                value: '""',
            },
        ]);
    }

    /**
     * Remove one Advanced Setting row.
     */
    function handleRemoveSetting(id: string): void {
        setSettingRows((rows) => rows.filter((row) => row.id !== id));
    }

    /**
     * Append an available project skill to the selected skill order.
     */
    function handleAddSkill(value: string): void {
        const skillId = Number(value);

        if (
            !Number.isInteger(skillId) ||
            selectedSkillIds.includes(skillId) ||
            !skills.some((skill) => skill.id === skillId)
        ) {
            setSkillToAdd('');

            return;
        }

        setSelectedSkillIds((skillIds) => [...skillIds, skillId]);
        setSkillToAdd('');
    }

    /**
     * Remove one assigned skill while preserving the order of the remainder.
     */
    function handleRemoveSkill(skillId: number): void {
        setSelectedSkillIds((skillIds) =>
            skillIds.filter((id) => id !== skillId),
        );
    }

    /**
     * Prevent submission while Advanced Settings contain invalid keys.
     */
    function handleBeforeSubmit(): boolean {
        return settingsClientError === null;
    }

    /**
     * Close the modal only after the server accepts and reloads the update.
     */
    function handleSuccessfulSave(): void {
        onClose();
    }

    return (
        <Dialog open onOpenChange={handleDialogOpenChange}>
            <DialogContent
                overlayClassName="bg-black/40 dark:bg-black/60"
                className="flex max-h-[calc(100dvh-1rem)] w-[calc(100vw-1rem)] max-w-[72rem] flex-col gap-0 overflow-hidden p-0 sm:w-[calc(100vw-2rem)] sm:max-w-[72rem]"
                onPointerDownOutside={(event) => event.preventDefault()}
            >
                <Form
                    {...AgentController.update.form([project, agent])}
                    cancelOnUnmount
                    disableWhileProcessing
                    options={{
                        preserveScroll: true,
                    }}
                    onBefore={handleBeforeSubmit}
                    onSuccess={handleSuccessfulSave}
                    className="flex min-h-0 flex-1 flex-col overflow-hidden"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader className="border-border shrink-0 border-b px-5 py-5 pr-14 sm:px-6">
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <Avatar className="border-border size-12 shrink-0 border shadow-xs">
                                            <AvatarImage
                                                src={avatarSrc}
                                                alt={`${agent.name} avatar`}
                                                className="object-cover"
                                            />
                                            <AvatarFallback>
                                                {agent.name
                                                    .slice(0, 2)
                                                    .toUpperCase()}
                                            </AvatarFallback>
                                        </Avatar>

                                        <div className="min-w-0">
                                            <DialogTitle className="truncate text-lg">
                                                Configure {agent.name}
                                            </DialogTitle>
                                            <DialogDescription className="mt-1 capitalize">
                                                {agent.role.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </DialogDescription>
                                        </div>
                                    </div>

                                    <div className="w-full sm:w-auto">
                                        <Select
                                            value={enabled ? '1' : '0'}
                                            onValueChange={handleEnabledChange}
                                        >
                                            <SelectTrigger
                                                aria-label="Agent status"
                                                className="w-full sm:w-36"
                                            >
                                                {enabled ? (
                                                    <CircleCheck
                                                        aria-hidden="true"
                                                        className="text-emerald-600 dark:text-emerald-400"
                                                    />
                                                ) : (
                                                    <CircleMinus
                                                        aria-hidden="true"
                                                        className="text-muted-foreground"
                                                    />
                                                )}
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent align="end">
                                                <SelectItem value="1">
                                                    Enabled
                                                </SelectItem>
                                                <SelectItem value="0">
                                                    Disabled
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <input
                                            type="hidden"
                                            name="enabled"
                                            value={enabled ? '1' : '0'}
                                        />
                                    </div>
                                </div>
                            </DialogHeader>

                            <div className="min-h-0 flex-1 overflow-y-auto">
                                <ConfigurationSection
                                    title="General"
                                    description="Basic identity for this agent."
                                >
                                    <div className="grid gap-4">
                                        <TextField
                                            id={`${fieldPrefix}-name`}
                                            label="Name"
                                            name="name"
                                            defaultValue={agent.name}
                                            error={errors.name}
                                        />

                                        <TextField
                                            id={`${fieldPrefix}-identity`}
                                            label="Identity"
                                            name="identity"
                                            defaultValue={agent.identity ?? ''}
                                            error={errors.identity}
                                            placeholder="Describe this Agent's role and identity."
                                        />
                                    </div>
                                </ConfigurationSection>

                                <ConfigurationSection
                                    title="AI Runtime"
                                    description="Configure the provider harness and model."
                                >
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor={`${fieldPrefix}-harness`}
                                            >
                                                Harness
                                            </Label>
                                            <Select
                                                name="harness"
                                                value={harness}
                                                onValueChange={
                                                    handleHarnessChange
                                                }
                                            >
                                                <SelectTrigger
                                                    id={`${fieldPrefix}-harness`}
                                                    className="w-full"
                                                    aria-invalid={Boolean(
                                                        errors.harness,
                                                    )}
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="codex">
                                                        Codex
                                                    </SelectItem>
                                                    <SelectItem value="claude">
                                                        Claude
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errors.harness}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor={`${fieldPrefix}-model`}
                                            >
                                                Model
                                            </Label>
                                            <Select
                                                name="model"
                                                value={model}
                                                onValueChange={setModel}
                                            >
                                                <SelectTrigger
                                                    id={`${fieldPrefix}-model`}
                                                    className="w-full"
                                                    aria-invalid={Boolean(
                                                        errors.model,
                                                    )}
                                                >
                                                    <SelectValue placeholder="Provider default" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {modelOptions.map(
                                                        (option) => (
                                                            <SelectItem
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={errors.model}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor={`${fieldPrefix}-reasoning`}
                                            >
                                                Reasoning
                                            </Label>
                                            <Select
                                                value={reasoning}
                                                onValueChange={setReasoning}
                                            >
                                                <SelectTrigger
                                                    id={`${fieldPrefix}-reasoning`}
                                                    className="w-full sm:max-w-xs"
                                                >
                                                    <SelectValue placeholder="Provider default" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {REASONING_OPTIONS.map(
                                                        (option) => (
                                                            <SelectItem
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                </ConfigurationSection>

                                <ConfigurationSection
                                    title="Prompting"
                                    description="Define the Agent's project context and workflow instructions."
                                >
                                    <div className="grid gap-4">
                                        <TextareaField
                                            id={`${fieldPrefix}-default-context`}
                                            label="Default Context"
                                            name="default_context"
                                            defaultValue={
                                                agent.default_context ?? ''
                                            }
                                            error={errors.default_context}
                                            rows={4}
                                        />

                                        <TextareaField
                                            id={`${fieldPrefix}-workflow-instructions`}
                                            label="Workflow Instructions"
                                            name="workflow_instructions"
                                            defaultValue={
                                                agent.workflow_instructions ??
                                                ''
                                            }
                                            error={errors.workflow_instructions}
                                            rows={4}
                                        />
                                    </div>
                                </ConfigurationSection>

                                <ConfigurationSection
                                    title="Advanced Settings"
                                    description="Store additional key-value configuration for this agent."
                                >
                                    <div className="grid gap-3">
                                        {settingRows.length > 0 && (
                                            <div className="text-muted-foreground hidden grid-cols-[minmax(0,1fr)_minmax(0,1fr)_2.25rem] gap-2 text-xs font-medium sm:grid">
                                                <span>Key</span>
                                                <span>Value</span>
                                                <span className="sr-only">
                                                    Actions
                                                </span>
                                            </div>
                                        )}

                                        {settingRows.map((row, index) => (
                                            <div
                                                key={row.id}
                                                className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_2.25rem] sm:items-center"
                                            >
                                                <div className="grid gap-1 sm:block">
                                                    <Label
                                                        htmlFor={`${fieldPrefix}-${row.id}-key`}
                                                        className="sm:sr-only"
                                                    >
                                                        Setting {index + 1} key
                                                    </Label>
                                                    <Input
                                                        id={`${fieldPrefix}-${row.id}-key`}
                                                        value={row.key}
                                                        required
                                                        placeholder="Key"
                                                        onChange={(event) =>
                                                            handleSettingChange(
                                                                row.id,
                                                                'key',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    />
                                                </div>

                                                <div className="grid gap-1 sm:block">
                                                    <Label
                                                        htmlFor={`${fieldPrefix}-${row.id}-value`}
                                                        className="sm:sr-only"
                                                    >
                                                        Setting {index + 1}{' '}
                                                        value
                                                    </Label>
                                                    <Input
                                                        id={`${fieldPrefix}-${row.id}-value`}
                                                        value={row.value}
                                                        placeholder='JSON value, for example 0.3 or "text"'
                                                        onChange={(event) =>
                                                            handleSettingChange(
                                                                row.id,
                                                                'value',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    />
                                                </div>

                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="justify-self-start sm:justify-self-auto"
                                                    aria-label={`Remove setting ${row.key || index + 1}`}
                                                    onClick={() =>
                                                        handleRemoveSetting(
                                                            row.id,
                                                        )
                                                    }
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                </Button>
                                            </div>
                                        ))}

                                        {settingRows.length === 0 && (
                                            <p className="text-muted-foreground text-sm">
                                                No advanced settings configured.
                                            </p>
                                        )}

                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="w-fit"
                                            onClick={handleAddSetting}
                                        >
                                            <Plus aria-hidden="true" />
                                            Add setting
                                        </Button>

                                        <input
                                            type="hidden"
                                            name="settings"
                                            value={serializedSettings}
                                        />

                                        <InputError
                                            message={
                                                settingsClientError ??
                                                errors.settings
                                            }
                                        />
                                    </div>
                                </ConfigurationSection>

                                <ConfigurationSection
                                    title="Assigned Skills"
                                    description="Choose the project Skills this agent can use."
                                >
                                    <div className="grid gap-4">
                                        {selectedSkills.length > 0 ? (
                                            <div className="flex flex-wrap gap-2">
                                                {selectedSkills.map((skill) => (
                                                    <div
                                                        key={skill.id}
                                                        className="border-input bg-background inline-flex max-w-full items-center gap-1 rounded-md border py-1 pr-1 pl-2.5 text-sm shadow-xs"
                                                    >
                                                        <span className="truncate">
                                                            {skill.name}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            className="text-muted-foreground hover:bg-accent hover:text-accent-foreground focus-visible:ring-ring flex size-6 shrink-0 items-center justify-center rounded-sm outline-none focus-visible:ring-2"
                                                            aria-label={`Remove ${skill.name}`}
                                                            onClick={() =>
                                                                handleRemoveSkill(
                                                                    skill.id,
                                                                )
                                                            }
                                                        >
                                                            <X
                                                                aria-hidden="true"
                                                                className="size-3.5"
                                                            />
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <p className="text-muted-foreground text-sm">
                                                No skills assigned.
                                            </p>
                                        )}

                                        {selectedSkillIds.map(
                                            (skillId, index) => (
                                                <span key={skillId}>
                                                    <input
                                                        type="hidden"
                                                        name="skill_ids[]"
                                                        value={skillId}
                                                    />
                                                    <input
                                                        type="hidden"
                                                        name={`skill_positions[${skillId}]`}
                                                        value={index + 1}
                                                    />
                                                </span>
                                            ),
                                        )}

                                        <div className="max-w-sm">
                                            <Select
                                                value={skillToAdd}
                                                disabled={
                                                    availableSkills.length === 0
                                                }
                                                onValueChange={handleAddSkill}
                                            >
                                                <SelectTrigger
                                                    aria-label="Add skill"
                                                    className="w-full"
                                                >
                                                    <Plus aria-hidden="true" />
                                                    <SelectValue
                                                        placeholder={
                                                            availableSkills.length ===
                                                            0
                                                                ? 'All skills assigned'
                                                                : 'Add skill'
                                                        }
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {availableSkills.map(
                                                        (skill) => (
                                                            <SelectItem
                                                                key={skill.id}
                                                                value={skill.id.toString()}
                                                            >
                                                                {skill.name}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <InputError
                                            message={errors['skill_ids']}
                                        />
                                    </div>
                                </ConfigurationSection>
                            </div>

                            <DialogFooter className="border-border bg-background shrink-0 border-t px-5 py-4 sm:px-6">
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        settingsClientError !== null
                                    }
                                >
                                    {processing ? 'Saving...' : 'Save changes'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
