import Foundation

/// Why a Molly document was refused.
///
/// The sentence is what the inspector and the pull request strip show. Codes stay in the
/// text so a journal and a test can agree without a second field.
public struct MollyContractError: Error, Sendable, Hashable, CustomStringConvertible {
    public var sentence: String
    public var description: String { sentence }

    public init(_ sentence: String) {
        self.sentence = sentence
    }
}
